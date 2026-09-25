<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
                'claudeCodeKey' => 'claude mcp add --transport http --header "Authorization: Bearer melf_…" melfina '.$endpoint,
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
                        'headers' => ['Authorization' => 'Bearer melf_…'],
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

        return redirect()
            ->route('console.agents.show', $agent)
            ->with('plaintext_key', $plaintext);
    }
}
