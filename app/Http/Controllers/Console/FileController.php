<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\VfsFile;
use App\Support\PathTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

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

        return view('console.files.show', [
            'agent' => $agent,
            'dir' => $dir,
            'dirs' => $level['dirs'],
            'files' => $level['files'],
            'breadcrumbs' => PathTree::breadcrumbs($dir),
            'fileCount' => $paths->count(),
        ]);
    }

    public function edit(Request $request, Agent $agent): View
    {
        $this->authorize('update', $agent);

        $path = (string) $request->query('path', '');
        $content = null;

        if ($path !== '') {
            try {
                $content = VfsFile::readFor($agent, $path);
            } catch (InvalidArgumentException) {
                abort(404);
            }

            abort_if($content === null, 404);
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
