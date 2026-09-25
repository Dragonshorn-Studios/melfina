<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\VfsFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class FileController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Agent::class);

        return view('console.files.index', [
            'agents' => Agent::query()->withCount('files')->orderBy('name')->get(),
        ]);
    }

    public function show(Agent $agent): View
    {
        $this->authorize('view', $agent);

        return view('console.files.show', [
            'agent' => $agent,
            'paths' => VfsFile::listFor($agent),
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
            VfsFile::writeFor($agent, $validated['path'], $validated['content']);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['path' => $e->getMessage()]);
        }

        return redirect()->route('console.files.show', $agent);
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        try {
            VfsFile::deleteFor($agent, $validated['path']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['path' => $e->getMessage()]);
        }

        return redirect()->route('console.files.show', $agent);
    }
}
