<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Mcp\PluginRepo;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class AgentController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Agent::class);

        return view('console.agents.index', [
            'agents' => Agent::query()->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Agent::class);

        return view('console.agents.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Agent::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $agent = Agent::query()->create(['name' => $validated['name']]);
        $plaintext = $agent->rotateKey();
        app(PluginRepo::class)->rebuildPackage($agent, $plaintext);

        return redirect()
            ->route('console.agents.show', $agent)
            ->with('plaintext_key', $plaintext);
    }

    public function show(Agent $agent): View
    {
        $this->authorize('view', $agent);

        return view('console.agents.show', ['agent' => $agent]);
    }

    /**
     * Per-client connection guides: OAuth 2.1 (DCR + PKCE) where the client
     * supports it, the agent's static Bearer key everywhere else.
     */
    public function connect(Agent $agent): View
    {
        $this->authorize('view', $agent);

        $endpoint = url('/mcp');

        return view('console.agents.connect', [
            'agent' => $agent,
            'endpoint' => $endpoint,
            'pluginUrl' => url('/plugins/melfg_…/melf_….git'),
            'snippets' => [
                'claudeDesktop' => json_encode([
                    'mcpServers' => [
                        'melfina' => [
                            'command' => 'npx',
                            'args' => ['-y', 'mcp-remote', $endpoint],
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'claudeCode' => 'claude mcp add --transport http melfina '.$endpoint,
                'claudeCodeKey' => 'claude mcp add --transport http'
                    .' --header "Authorization: Bearer melfg_…"'
                    .' --header "X-Melfina-Agent-Key: melf_…"'
                    .' melfina '.$endpoint,
                'cursor' => json_encode([
                    'mcpServers' => [
                        'melfina' => ['url' => $endpoint],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'openCode' => json_encode([
                    '$schema' => 'https://opencode.ai/config.json',
                    'mcp' => [
                        'melfina' => [
                            'type' => 'remote',
                            'url' => $endpoint,
                            'enabled' => true,
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'minimax' => json_encode([
                    'melfina' => [
                        'url' => $endpoint,
                        'headers' => [
                            'Authorization' => 'Bearer melfg_…',
                            'X-Melfina-Agent-Key' => 'melf_…',
                        ],
                        'enabled' => true,
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ],
        ]);
    }

    public function edit(Agent $agent): View
    {
        $this->authorize('update', $agent);

        return view('console.agents.edit', ['agent' => $agent]);
    }

    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:'.Agent::STATUS_ACTIVE.','.Agent::STATUS_DISABLED],
        ]);

        $agent->update($validated);

        return redirect()->route('console.agents.show', $agent);
    }

    /**
     * Rotate the agent key: stores only the new hash and flashes the
     * plaintext once. The old key stops resolving immediately.
     */
    public function rotate(Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $plaintext = $agent->rotateKey();
        $repos = app(PluginRepo::class);
        $repos->rebuildPackage($agent, $plaintext);
        $repos->dropRepo($agent);

        return redirect()
            ->route('console.agents.show', $agent)
            ->with('plaintext_key', $plaintext);
    }

    /**
     * Download the agent's plugin package as a ZIP — for clients that take
     * an upload rather than a cloneable repo URL. Contains live keys.
     */
    public function pluginZip(Agent $agent, PluginRepo $repos): BinaryFileResponse
    {
        $this->authorize('view', $agent);

        $dir = $repos->packageDir($agent);
        abort_unless(is_dir($dir), 404);

        $zipPath = tempnam(sys_get_temp_dir(), 'melfina-plugin-');
        unlink($zipPath);
        $zipPath .= '.zip';

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach (glob($dir.'/{,.}*', GLOB_BRACE) as $root) {
            $this->zipTree($zip, $root, $dir);
        }

        $zip->close();

        $slug = Str::slug($agent->name) ?: 'agent';

        return response()->download($zipPath, 'melfina-'.$slug.'.zip')->deleteFileAfterSend();
    }

    private function zipTree(ZipArchive $zip, string $path, string $base): void
    {
        $name = basename($path);

        if ($name === '.' || $name === '..') {
            return;
        }

        if (is_dir($path)) {
            foreach (glob($path.'/{,.}*', GLOB_BRACE) ?: [] as $child) {
                $this->zipTree($zip, $child, $base);
            }

            return;
        }

        $zip->addFile($path, ltrim(substr($path, strlen($base)), '/'));
    }
}
