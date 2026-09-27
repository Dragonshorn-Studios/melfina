<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\SharedAcl;
use App\Models\SharedFile;
use App\Models\SharedSpace;
use App\Models\VfsFile;
use App\Support\FileMime;
use App\Support\PathTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class SharedSpaceController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', SharedSpace::class);

        return view('console.shared.index', [
            'spaces' => SharedSpace::query()->withCount(['aclEntries', 'files'])->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', SharedSpace::class);

        return view('console.shared.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SharedSpace::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:shared_spaces,name'],
        ]);

        $space = SharedSpace::query()->create($validated);

        return redirect()->route('console.shared.edit', $space);
    }

    /**
     * Browse the space's files one directory level at a time — dirs as
     * links under a breadcrumb, files with size/open/delete. The owner
     * can look inside anything agents share, no ACL needed.
     */
    public function show(Request $request, SharedSpace $shared): View
    {
        $this->authorize('view', $shared);

        $dir = PathTree::normalizeDir($request->string('dir')->toString());
        $paths = $shared->files()->orderBy('path')->pluck('path');
        $level = PathTree::level($paths, $dir);

        // Files at exactly this level: bound prefix match + no '/' in
        // the remainder — avoids binding one param per path.
        $files = $shared->files()
            ->when($dir !== '', fn ($query) => $query->whereRaw('substr(path, 1, ?) = ?', [strlen($dir), $dir]))
            ->whereRaw('instr(substr(path, ?), ?) = 0', [strlen($dir) + 1, '/'])
            ->orderBy('path')
            ->select(['id', 'shared_space_id', 'path', 'mime', 'updated_at'])
            ->selectRaw('length(coalesce(content_blob, content)) as byte_size')
            ->paginate(50)
            ->withQueryString();

        return view('console.shared.show', [
            'space' => $shared,
            'dir' => $dir,
            'dirs' => $level['dirs'],
            'breadcrumbs' => PathTree::breadcrumbs($dir),
            'files' => $files,
            'fileCount' => $paths->count(),
        ]);
    }

    /**
     * Delete one file inside the space. The path is the row's normalized
     * key, echoed back by the listing — same contract as agent file
     * deletion.
     */
    public function destroyFile(Request $request, SharedSpace $shared): RedirectResponse
    {
        $this->authorize('update', $shared);

        $validated = $request->validate([
            'path' => ['required', 'string'],
            'dir' => ['nullable', 'string'],
        ]);

        try {
            SharedFile::deleteFor($shared, $validated['path']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['path' => $e->getMessage()]);
        }

        return redirect()->route('console.shared.show', [
            'shared' => $shared,
            'dir' => PathTree::normalizeDir($validated['dir'] ?? null),
        ]);
    }

    /**
     * Read one file inside the space. The path comes via query string so
     * nested paths don't fight route params.
     */
    public function file(Request $request, SharedSpace $shared): View
    {
        $this->authorize('view', $shared);

        $path = $request->string('path')->toString();
        $file = $shared->files()->where('path', $path)->first();

        abort_if($file === null, 404);

        return view('console.shared.file', [
            'space' => $shared,
            'file' => $file,
            'dir' => PathTree::normalizeDir($request->string('dir')->toString()),
            'inlineSafe' => $file->isBinary() && FileMime::isInlineSafe($file->mime),
        ]);
    }

    /**
     * Multipart upload into the space — mirrors the agent file-tree
     * upload: current dir + filename unless overridden, text bodies stay
     * text rows.
     */
    public function upload(Request $request, SharedSpace $shared): RedirectResponse
    {
        $this->authorize('update', $shared);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.(VfsFile::MAX_BINARY_BYTES / 1024)],
            'dir' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $dir = PathTree::normalizeDir($validated['dir'] ?? null);
        $upload = $request->file('file');
        $name = trim((string) ($validated['name'] ?? ''));
        $target = $dir.($name !== '' ? $name : basename($upload->getClientOriginalName()));

        $bytes = $upload->get();

        try {
            $file = FileMime::isTextual($bytes, FileMime::normalize($upload->getMimeType()))
                ? SharedFile::writeFor($shared, $target, $bytes)
                : SharedFile::writeBinaryFor($shared, $target, $bytes, $upload->getMimeType());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $parent = str_contains($file->path, '/') ? Str::beforeLast($file->path, '/').'/' : '';

        return redirect()->route('console.shared.show', ['shared' => $shared, 'dir' => $parent]);
    }

    /**
     * Raw bytes for a file in the space — same inline-mime allowlist as
     * the agent file raw route; agent-authored HTML/SVG never renders in
     * the owner's session.
     */
    public function rawFile(Request $request, SharedSpace $shared): Response
    {
        $this->authorize('view', $shared);

        try {
            $file = SharedFile::fileFor($shared, $request->string('path')->toString());
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_if($file === null, 404);

        $mime = $file->mime ?? 'text/plain';
        $inline = FileMime::isInlineSafe($mime);

        return response($file->bytes(), 200, [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addslashes(basename($file->path)).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function edit(SharedSpace $shared): View
    {
        $this->authorize('update', $shared);

        $grants = $shared->aclEntries()->get()->keyBy('agent_id');

        return view('console.shared.edit', [
            'space' => $shared,
            'agents' => Agent::query()->orderBy('name')->get(),
            'grants' => $grants,
        ]);
    }

    public function update(Request $request, SharedSpace $shared): RedirectResponse
    {
        $this->authorize('update', $shared);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('shared_spaces', 'name')->ignore($shared->id)],
            'acl' => ['nullable', 'array'],
            'acl.*' => ['nullable', Rule::in([SharedAcl::PERMISSION_READ, SharedAcl::PERMISSION_WRITE, 'none'])],
        ]);

        $agentIds = Agent::query()->pluck('id');
        $acl = collect($validated['acl'] ?? [])
            ->only($agentIds->all())
            ->reject(fn (string $permission) => $permission === 'none');

        DB::transaction(function () use ($shared, $validated, $acl): void {
            $shared->update(['name' => $validated['name']]);

            $shared->aclEntries()->whereNotIn('agent_id', $acl->keys()->all())->delete();

            foreach ($acl as $agentId => $permission) {
                $shared->aclEntries()->updateOrCreate(
                    ['agent_id' => $agentId],
                    ['permission' => $permission],
                );
            }
        });

        return redirect()->route('console.shared.edit', $shared);
    }
}
