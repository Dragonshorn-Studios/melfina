<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Mcp\PluginRepo;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'name' => ['required', 'string', 'max:255', Rule::unique('agents', 'name')],
        ]);

        $agent = Agent::query()->create(['name' => $validated['name']]);
        $plaintext = $agent->rotateKey();

        // Packaging is a side effect of key issuance, not part of it — a
        // filesystem failure must not eat the shown-once plaintext.
        try {
            app(PluginRepo::class)->syncForAgent($agent, $plaintext);
        } catch (\Throwable $e) {
            Log::warning('plugin package rebuild failed', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
        }

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

        // The clone URL carries credentials as userinfo — git folds
        // `user:pass@` into an Authorization header, so keys never appear
        // in request-path logs. Username is the persona, password the key.
        $personaUser = rawurlencode($agent->name);
        $repoPath = url('/plugins/'.(Str::slug($agent->name) ?: 'agent').'.git');

        return view('console.agents.connect', [
            'agent' => $agent,
            'endpoint' => $endpoint,
            // '${1}' braces keep a digit-leading persona ('7of9') from being
            // consumed as part of the capture-group reference.
            'pluginUrl' => preg_replace('#^(https?://)#', '${1}'.$personaUser.':melfg_…@', $repoPath),
            'pluginUrlDirect' => preg_replace('#^(https?://)#', '${1}'.$personaUser.':melf_…@', $repoPath),
            // One clone URL for the whole install — persona-free, gated by
            // the fleet key; each agent writes its own name into the
            // package's MCP URL.
            'pluginUrlFleet' => preg_replace('#^(https?://)#', '${1}melfina:melfg_…@', url('/plugins/~melfina.git')),
            // URL-only clients fold persona + fleet key into the endpoint
            // as Basic userinfo: https://{name}:{fleet}@host/mcp.
            'endpointPersonaUrl' => preg_replace('#^(https?://)#', '${1}'.$personaUser.':melfg_…@', $endpoint),
            'hasPackage' => is_dir(app(PluginRepo::class)->packageDir($agent)),
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
                    .' --header '.escapeshellarg('X-Melfina-Agent-Key: '.$agent->name)
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
                            'X-Melfina-Agent-Key' => $agent->name,
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
            'name' => ['required', 'string', 'max:255', Rule::unique('agents', 'name')->ignore($agent->id)],
            'status' => ['required', 'string', 'in:'.Agent::STATUS_ACTIVE.','.Agent::STATUS_DISABLED],
        ]);

        $agent->update($validated);

        // The name is the fleet persona, and a disabled agent shouldn't
        // leave credential-bearing artifacts at rest — both make built
        // plugin artifacts stale. Without plaintext they can only be
        // dropped, not rebuilt (next key rotation regenerates the ZIP).
        if ($agent->wasChanged('name') || $agent->wasChanged('status')) {
            try {
                app(PluginRepo::class)->syncForAgent($agent);
            } catch (\Throwable $e) {
                Log::warning('plugin artifacts drop failed', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
            }
        }

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

        try {
            app(PluginRepo::class)->syncForAgent($agent, $plaintext);
        } catch (\Throwable $e) {
            Log::warning('plugin artifacts refresh failed', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
        }

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
        abort_unless(class_exists(ZipArchive::class), 500, 'PHP ext-zip is not installed');

        // tempnam's file is 0600 — keep that path (OVERWRITE truncates it)
        // so the credential-bearing archive is never world-readable.
        $zipPath = tempnam(sys_get_temp_dir(), 'melfina-plugin-');

        $zip = new ZipArchive;
        abort_unless($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);

        // allFiles with $hidden=true ships the dot-prefixed manifests
        // (.minimax-plugin/, .claude-plugin/, .mcp.json) — and needs no
        // GLOB_BRACE, which musl/Alpine PHP builds lack.
        foreach (File::allFiles($dir, hidden: true) as $file) {
            $zip->addFile($file->getPathname(), $file->getRelativePathname());
        }

        abort_unless($zip->close(), 500);
        chmod($zipPath, 0600);

        $slug = Str::slug($agent->name) ?: 'agent';

        return response()->download($zipPath, 'melfina-'.$slug.'.zip')->deleteFileAfterSend();
    }
}
