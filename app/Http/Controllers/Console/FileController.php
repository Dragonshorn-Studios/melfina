<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\VfsFile;
use App\Support\FileMime;
use App\Support\PathTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class FileController extends Controller
{
    /**
     * The sidebar agent list is the file browser entry point now — the
     * global index folds into it.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('console.agents.index');
    }

    /**
     * One directory level of the agent's tree — dirs as links under a
     * breadcrumb, files with open/delete. Same traversal as shared
     * spaces.
     */
    public function show(Request $request, Agent $agent): View
    {
        $this->authorize('view', $agent);

        $dir = PathTree::normalizeDir($request->string('dir')->toString());
        $paths = VfsFile::listFor($agent);
        $level = PathTree::level($paths, $dir);

        // Mime + size for the rows at this level — the listing itself stays
        // path-driven; the blob column's length() doubles as the byte size.
        $meta = VfsFile::query()
            ->where('agent_id', $agent->id)
            ->whereIn('path', $level['files'])
            ->select(['path', 'mime'])
            // SQLite's length() counts TEXT characters until NUL; force blob semantics for binary rows.
            ->selectRaw('length(cast(coalesce(content_blob, content) as blob)) as byte_size')
            ->get()
            ->keyBy('path');

        return view('console.files.show', [
            'agent' => $agent,
            'dir' => $dir,
            'dirs' => $level['dirs'],
            'files' => $level['files'],
            'meta' => $meta,
            'breadcrumbs' => PathTree::breadcrumbs($dir),
            'fileCount' => $paths->count(),
        ]);
    }

    /**
     * Multipart upload into the tree — the console's binary write path.
     * The target path is the current dir plus the upload's filename unless
     * the owner types a different one. Text bodies stay text rows.
     */
    public function upload(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

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
                ? VfsFile::writeFor($agent, $target, $bytes)
                : VfsFile::writeBinaryFor($agent, $target, $bytes, $upload->getMimeType());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $parent = str_contains($file->path, '/') ? Str::beforeLast($file->path, '/').'/' : '';

        return redirect()->route('console.files.show', ['agent' => $agent, 'dir' => $parent]);
    }

    /**
     * The viewer page: images inline, other binaries as downloads, text as
     * a read-only block with an edit link. Binary files never reach the
     * textarea editor.
     */
    public function view(Request $request, Agent $agent): View
    {
        $this->authorize('view', $agent);

        $path = (string) $request->query('path', '');

        try {
            $file = VfsFile::fileFor($agent, $path);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_if($file === null, 404);

        return view('console.files.view', [
            'agent' => $agent,
            'file' => $file,
            'dir' => PathTree::normalizeDir($request->string('dir')->toString()),
            'inlineSafe' => $file->isBinary() && FileMime::isInlineSafe($file->mime),
        ]);
    }

    /**
     * Raw bytes for the viewer's <img> and the download link. Only a
     * mime allowlist renders inline — an agent-written HTML/SVG file must
     * never execute in the owner's session.
     */
    public function raw(Request $request, Agent $agent): Response
    {
        $this->authorize('view', $agent);

        try {
            $file = VfsFile::fileFor($agent, (string) $request->query('path', ''));
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

    public function edit(Request $request, Agent $agent): View|RedirectResponse
    {
        $this->authorize('update', $agent);

        $path = (string) $request->query('path', '');
        $content = null;

        if ($path !== '') {
            try {
                $file = VfsFile::fileFor($agent, $path);
            } catch (InvalidArgumentException) {
                abort(404);
            }

            abort_if($file === null, 404);

            // Binaries go to the viewer — a textarea full of raw bytes is
            // useless and corrupts on save.
            if ($file->isBinary()) {
                return redirect()->route('console.files.view', [
                    'agent' => $agent,
                    'path' => $file->path,
                    'dir' => $request->string('dir')->toString(),
                ]);
            }

            $content = $file->content;
        }

        return view('console.files.edit', [
            'agent' => $agent,
            'path' => $path,
            'content' => $content,
            'dir' => PathTree::normalizeDir($request->string('dir')->toString()),
        ]);
    }

    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'path' => ['required', 'string'],
            'content' => ['required', 'string'],
        ]);

        try {
            $file = VfsFile::writeFor($agent, $validated['path'], $validated['content']);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['path' => $e->getMessage()]);
        }

        // Land in the directory the file actually lives in.
        $parent = str_contains($file->path, '/') ? Str::beforeLast($file->path, '/').'/' : '';

        return redirect()->route('console.files.show', ['agent' => $agent, 'dir' => $parent]);
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'path' => ['required', 'string'],
            'dir' => ['nullable', 'string'],
        ]);

        try {
            VfsFile::deleteFor($agent, $validated['path']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['path' => $e->getMessage()]);
        }

        return redirect()->route('console.files.show', [
            'agent' => $agent,
            'dir' => PathTree::normalizeDir($validated['dir'] ?? null),
        ]);
    }
}
