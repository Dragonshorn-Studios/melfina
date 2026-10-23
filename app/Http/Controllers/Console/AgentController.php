<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Mcp\PackageVariant;
use App\Mcp\PluginRepo;
use App\Mcp\Servers\MelfinaServer;
use App\Mcp\ToolCatalog;
use App\Mcp\Tools\ReadInstructions;
use App\Mcp\Tools\ReadPersona;
use App\Models\Agent;
use App\Models\AgentApiKey;
use App\Models\AgentEnrollment;
use App\Models\AgentEntityReference;
use App\Models\AgentEntityReferenceKind;
use App\Models\AgentGroup;
use App\Models\KnowledgeEntry;
use App\Rules\UnreservedAgentName;
use App\Services\KnowledgeIdentityAdmin;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Mcp\Server\Attributes\Instructions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AgentController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Agent::class);

        return view('console.agents.index', [
            'agents' => Agent::query()
                ->withCount('files')
                ->withMax('files', 'updated_at')
                ->orderBy('name')
                ->get(),
            'enrollments' => AgentEnrollment::query()
                ->with('agent')
                ->orderByRaw("case status when 'pending' then 0 else 1 end")
                ->latest()
                ->get(),
            'apiScopes' => app(ToolCatalog::class)->scopeChoices(),
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
            'name' => ['required', 'string', 'max:255', Rule::unique('agents', 'name'), new UnreservedAgentName],
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
            'entityReferences' => $agent->entityReferences()->with('entry')->orderBy('normalized_value')->get(),
            'addressingPreferences' => $agent->addressingPreferences()->with('person')->orderBy('id')->get(),
            'people' => KnowledgeEntry::query()->where('type', 'person')->orderBy('name_lower')->get(['id', 'name']),
            'referenceKinds' => AgentEntityReferenceKind::values(),
            'groups' => AgentGroup::query()->with('mainPerson')->orderBy('name')->get(),
        ]);
    }

    public function storeMainPerson(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'main_person_entry_id' => ['sometimes', 'nullable', 'integer', 'exists:knowledge_entries,id'],
            'groups' => ['sometimes', 'array'],
            'groups.*' => ['integer', 'exists:agent_groups,id'],
            'membership' => ['sometimes', 'boolean'],
        ]);

        $notes = [];

        if ($request->exists('main_person_entry_id')) {
            $personId = $validated['main_person_entry_id'] ?? null;
            if ($personId !== null && KnowledgeEntry::query()->whereKey($personId)->where('type', 'person')->doesntExist()) {
                throw ValidationException::withMessages([
                    'main_person_entry_id' => 'The main person must be a person entry.',
                ]);
            }

            $agent->main_person_entry_id = $personId;
            $agent->save();
            $notes[] = 'Main person saved.';
        }

        if ($request->exists('groups') || $request->boolean('membership')) {
            $agent->groups()->sync($validated['groups'] ?? []);
            $notes[] = 'Groups saved.';
        }

        return redirect()
            ->route('console.agents.show', $agent)
            ->with('status', implode(' ', $notes) ?: 'Saved.');
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

    public function storeReference(Request $request, Agent $agent, KnowledgeIdentityAdmin $admin): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'value' => ['required', 'string', 'max:200'],
            'entry_id' => ['required', 'integer', 'exists:knowledge_entries,id'],
            'kind' => ['required', 'string', Rule::in(AgentEntityReferenceKind::values())],
        ]);

        $entry = KnowledgeEntry::query()->findOrFail($validated['entry_id']);
        $admin->bindReference(
            [$agent],
            $entry,
            $validated['value'],
            AgentEntityReferenceKind::from($validated['kind']),
        );

        return redirect()->route('console.agents.show', $agent)->with('status', 'Contextual reference saved.');
    }

    public function destroyReference(Agent $agent, AgentEntityReference $reference): RedirectResponse
    {
        $this->authorize('update', $agent);
        abort_unless($reference->agent_id === $agent->id, 404);

        $reference->delete();

        return redirect()->route('console.agents.show', $agent)->with('status', 'Contextual reference removed.');
    }

    public function storeAddressingPreference(Request $request, Agent $agent, KnowledgeIdentityAdmin $admin): RedirectResponse
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'person_entry_id' => ['required', 'integer', 'exists:knowledge_entries,id'],
            'form' => ['required', 'string', 'max:200'],
            'context' => ['nullable', 'string', 'max:80'],
        ]);

        $person = KnowledgeEntry::query()->findOrFail($validated['person_entry_id']);
        $admin->setAddressingPreference(
            $agent,
            $person,
            $validated['form'],
            $validated['context'] ?: 'default',
        );

        return redirect()->route('console.agents.show', $agent)->with('status', 'Addressing preference stored. It is not an instruction.');
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
        $repoPathSingle = url('/plugins/'.(Str::slug($agent->name) ?: 'agent').'.single.git');

        return view('console.agents.connect', [
            'agent' => $agent,
            'endpoint' => $endpoint,
            'apiToolsIndex' => url('/api/tools'),
            // '${1}' braces keep a digit-leading persona ('7of9') from being
            // consumed as part of the capture-group reference.
            'pluginUrl' => preg_replace('#^(https?://)#', '${1}'.$personaUser.':melfg_…@', $repoPath),
            'pluginUrlDirect' => preg_replace('#^(https?://)#', '${1}'.$personaUser.':melf_…@', $repoPath),
            // The `.single.git` repo serves the umbrella+script layout.
            'pluginUrlSingle' => preg_replace('#^(https?://)#', '${1}'.$personaUser.':melfg_…@', $repoPathSingle),
            // One clone URL for the whole install — persona-free, gated by
            // the fleet key; each agent writes its own name into the
            // package's MCP URL.
            'pluginUrlFleet' => preg_replace('#^(https?://)#', '${1}melfina:melfg_…@', url('/plugins/~melfina.git')),
            // URL-only clients fold persona + fleet key into the endpoint
            // as Basic userinfo: https://{name}:{fleet}@host/mcp.
            'endpointPersonaUrl' => preg_replace('#^(https?://)#', '${1}'.$personaUser.':melfg_…@', $endpoint),
            'hasPackage' => is_dir(app(PluginRepo::class)->packageDir($agent)),
        ]);
    }

    /**
     * Read-only preview of everything the agent receives: the exact
     * read_instructions / read_persona payloads, the MCP server's
     * instructions blurb, the generated umbrella skill, and the tool
     * surface — so the owner verifies the composed result without
     * calling the tools.
     */
    public function preview(Agent $agent, ToolCatalog $catalog, PluginRepo $repos): View
    {
        $this->authorize('view', $agent);

        $serverInstructions = collect(
            (new \ReflectionClass(MelfinaServer::class))->getAttributes(Instructions::class)
        )->first()?->newInstance()->value;

        return view('console.agents.preview', [
            'agent' => $agent,
            'serverInstructions' => $serverInstructions,
            'instructionsPayload' => ReadInstructions::payload($agent),
            'personaPayload' => ReadPersona::payload($agent),
            'umbrellaSkill' => $repos->umbrellaSkill($agent),
            'fleetUmbrellaSkill' => $repos->fleetUmbrellaSkill(),
            'tools' => $catalog->all(),
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
            'name' => ['required', 'string', 'max:255', Rule::unique('agents', 'name')->ignore($agent->id), new UnreservedAgentName],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'string', 'in:'.Agent::STATUS_ACTIVE.','.Agent::STATUS_DISABLED],
        ]);

        $originalName = $agent->name;
        $agent->update($validated);

        // `updated_by` is the knowledge graph's only per-agent attribution
        // and it is keyed by name — carry it across a rename so per-brain
        // counts (admin API) don't orphan under the old name or hand the
        // metric to a future agent that recycles it. Version history keeps
        // the name each write actually happened under.
        if ($agent->wasChanged('name')) {
            KnowledgeEntry::query()
                ->where('updated_by', $originalName)
                ->update(['updated_by' => $agent->name]);
        }

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
    public function pluginZip(Request $request, Agent $agent, PluginRepo $repos): BinaryFileResponse
    {
        $this->authorize('view', $agent);

        $dir = $repos->packageDir($agent);
        abort_unless(is_dir($dir), 404);

        $variant = PackageVariant::fromRequest($request->query('variant'));

        try {
            // packageZip reads the credential files from the persisted
            // package and rebuilds the generated half fresh under a lock,
            // so a package built before a catalog change doesn't ship
            // stale skills — and either layout comes out of the same dir.
            $zipPath = $repos->packageZip($agent, $variant);
        } catch (\RuntimeException $e) {
            abort(500, $e->getMessage());
        } catch (LockTimeoutException) {
            abort(500, 'Package build is busy — retry in a moment.');
        }

        $slug = Str::slug($agent->name) ?: 'agent';
        $suffix = $variant === PackageVariant::Multi ? '' : '-'.$variant->value;

        return response()->download($zipPath, 'melfina-'.$slug.$suffix.'.zip')->deleteFileAfterSend();
    }
}
