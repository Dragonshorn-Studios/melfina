<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Mcp\PluginRepo;
use App\Mcp\ToolCatalog;
use App\Models\Agent;
use App\Models\AgentApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            'name' => ['required', 'string', 'max:255', Rule::unique('agents', 'name'), $this->reservedNameRule()],
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

    public function show(Agent $agent, ToolCatalog $catalog): View
    {
        $this->authorize('view', $agent);

        return view('console.agents.show', [
            'agent' => $agent,
            'apiKeys' => $agent->apiKeys()->whereNull('revoked_at')->latest()->get(),
            'apiScopes' => $catalog->scopeChoices(),
        ]);
    }

    /**
     * Issue a scoped API key (`melfs_…`) for HTTP-fallback tool calls —
     * least-privilege credentials for weaker/cloud agents that can't run
     * MCP. The plaintext flashes once; only the hash is stored.
     */
    public function storeApiKey(Request $request, Agent $agent, ToolCatalog $catalog): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'key_name' => ['required', 'string', 'max:60'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in($catalog->scopeChoices())],
        ]);

        $plaintext = AgentApiKey::issue($agent, $validated['key_name'], $validated['scopes']);

        return redirect()
            ->route('console.agents.show', $agent)
            ->with('plaintext_key', $plaintext);
    }

    public function destroyApiKey(Agent $agent, AgentApiKey $agentApiKey): RedirectResponse
    {
        $this->authorize('update', $agent);
        abort_unless($agentApiKey->agent_id === $agent->id, 404);

        $agentApiKey->revoke();

        return redirect()->route('console.agents.show', $agent);
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
            'apiToolsIndex' => url('/api/tools'),
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
            'name' => ['required', 'string', 'max:255', Rule::unique('agents', 'name')->ignore($agent->id), $this->reservedNameRule()],
            'description' => ['nullable', 'string', 'max:500'],
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
     * The persona + memory editor: the persona text is free-form (same
     * limits as the update_persona MCP tool) and the memory log is the
     * agent's append-only journal — the owner can read it, append an
     * entry, or prune one.
     */
    public function editPersona(Agent $agent): View
    {
        $this->authorize('update', $agent);

        return view('console.agents.persona', ['agent' => $agent]);
    }

    public function updatePersona(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'persona' => ['nullable', 'string', 'max:20000'],
            'remember' => ['nullable', 'string', 'max:2000'],
        ]);

        // Both writes in one transaction — a failed remember must not
        // leave a half-applied persona edit behind.
        DB::transaction(function () use ($agent, $request, $validated): void {
            // Only touch persona when the field was actually submitted — a
            // bare "append memory" request must not wipe it.
            if ($request->has('persona')) {
                $agent->persona = $validated['persona'];
                $agent->save();
            }

            if (filled($validated['remember'] ?? null)) {
                $agent->remember($validated['remember']);
            }
        });

        return redirect()->route('console.agents.persona.edit', $agent);
    }

    /**
     * Prune one memory entry by position. Indexes shift after every write,
     * so the form echoes a hash of the entry it rendered — a stale page
     * pointing at a shifted index 404s instead of pruning the wrong entry.
     */
    public function destroyMemory(Request $request, Agent $agent, int $index): RedirectResponse
    {
        $this->authorize('update', $agent);

        DB::transaction(function () use ($request, $agent, $index): void {
            // Re-read inside the transaction: the imp appends to this log
            // autonomously, and a fresh read keeps an interleaved append
            // from being clobbered by the read-modify-write.
            $agent->refresh();

            $memories = $agent->memories ?? [];
            abort_unless(isset($memories[$index]), 404);
            abort_unless(
                hash_equals(md5(json_encode($memories[$index])), $request->string('expect')->toString()),
                404,
            );

            unset($memories[$index]);
            $agent->memories = array_values($memories);
            $agent->save();
        });

        return redirect()->route('console.agents.persona.edit', $agent);
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

        try {
            // packageZip refreshes the credential-free generated files
            // under a lock so a package built before a catalog change
            // doesn't ship stale skills.
            $zipPath = $repos->packageZip($agent);
        } catch (\RuntimeException $e) {
            abort(500, $e->getMessage());
        }

        $slug = Str::slug($agent->name) ?: 'agent';

        return response()->download($zipPath, 'melfina-'.$slug.'.zip')->deleteFileAfterSend();
    }

    /**
     * Reject names whose plugin-repo slug would collide with a reserved
     * repo path — the public fleet package's derived name shadows any
     * per-agent clone URL that maps to it.
     */
    private function reservedNameRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (Str::slug((string) $value).'.git' === app(PluginRepo::class)->publicRepoName()) {
                $fail('This name is reserved by the fleet plugin repo.');
            }
        };
    }
}
