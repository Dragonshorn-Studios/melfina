<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mcp\PluginRepo;
use App\Models\AdminApiKey;
use App\Models\Agent;
use App\Models\KnowledgeEntry;
use App\Rules\UnreservedAgentName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The provisioning surface (/api/admin) — machine-to-machine management
 * of brains for a fleet system such as Yukihime. The consumer holds an
 * owner-minted admin key (`melfa_…`, resolved by ResolveAdminKey) whose
 * permissions each endpoint checks; agents keep authenticating to /mcp
 * and /api/tools with their own `melf_`/`melfs_` credentials.
 *
 * Counts and last activity are per brain: files from the tree,
 * memories from the agent's own log, knowledge from graph entries the
 * agent last wrote (the graph is install-global; `updated_by` is the
 * only per-agent attribution it records).
 */
class AdminController extends Controller
{
    /**
     * Connectivity check for the consumer: product identity, version,
     * health. The `schema` middleware already 503s this when migrations
     * are pending, so a 200 here means the surface is usable.
     */
    public function status(Request $request): JsonResponse
    {
        return $this->permit($request, AdminApiKey::PERMISSION_STATUS_READ) ?? response()->json([
            'ok' => true,
            'product' => 'Melfina',
            'version' => config('melfina.version'),
            'status' => 'ok',
        ]);
    }

    /**
     * Every brain on the install — active and disabled — so a consumer
     * UI can offer "attach existing brain".
     */
    public function index(Request $request): JsonResponse
    {
        return $this->permit($request, AdminApiKey::PERMISSION_AGENTS_READ) ?? $this->respondWithAgents(
            Agent::query()
                ->withCount('files')
                ->withMax('files', 'updated_at')
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * Provision a brain. `persona` is the seed text — it lands in the
     * same field the console's persona tab edits. No `melf_` key is
     * minted here: issuing one is the caller's separate `keys:mint`
     * call, so an agents:write-only consumer can't mint credentials.
     */
    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->permit($request, AdminApiKey::PERMISSION_AGENTS_WRITE)) {
            return $denied;
        }

        // Same fold as the tools surface: the whole admin API speaks
        // {ok, error}, so a 422 carries the envelope plus a `fields`
        // map instead of Laravel's default {message, errors} shape.
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255', Rule::unique('agents', 'name'), new UnreservedAgentName],
                'description' => ['nullable', 'string', 'max:500'],
                'persona' => ['nullable', 'string', 'max:20000'],
            ]);
        } catch (ValidationException $e) {
            return $this->fail('Invalid input.', 422, ['fields' => $e->errors()]);
        }

        $agent = Agent::query()->create([
            'name' => $validated['name'],
            'status' => Agent::STATUS_ACTIVE,
            'description' => $validated['description'] ?? null,
            'persona' => $validated['persona'] ?? null,
        ]);

        // With no plaintext key in hand there is nothing to embed —
        // packaging rebuilds the day a key gets minted. A filesystem
        // failure must not eat the create either way.
        try {
            app(PluginRepo::class)->syncForAgent($agent);
        } catch (\Throwable $e) {
            Log::warning('plugin package sync failed', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'ok' => true,
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $this->slug($agent),
                'status' => $agent->status,
            ],
        ], 201);
    }

    /**
     * One brain in detail for the consumer's agent panel.
     */
    public function show(Request $request, int $agent): JsonResponse
    {
        if ($denied = $this->permit($request, AdminApiKey::PERMISSION_AGENTS_READ)) {
            return $denied;
        }

        $model = Agent::query()
            ->withCount('files')
            ->withMax('files', 'updated_at')
            ->find($agent);

        if ($model === null) {
            return $this->fail('Unknown agent.', 404);
        }

        return response()->json([
            'ok' => true,
            'agent' => [
                ...$this->agentPayload($model, $this->knowledgeCount($model->name)),
                'description' => $model->description,
                'key_issued_at' => $model->key_issued_at?->toIso8601String(),
                'updated_at' => $model->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Mint/rotate the agent's normal `melf_` key. The plaintext is
     * returned once; only the hash is stored, same as the console's
     * rotate action.
     */
    public function rotateKey(Request $request, int $agent): JsonResponse
    {
        if ($denied = $this->permit($request, AdminApiKey::PERMISSION_KEYS_MINT)) {
            return $denied;
        }

        $model = Agent::query()->find($agent);

        if ($model === null) {
            return $this->fail('Unknown agent.', 404);
        }

        $plaintext = $model->rotateKey();

        // Packaging is a side effect of key issuance, not part of it — a
        // filesystem failure must not eat the shown-once plaintext.
        try {
            app(PluginRepo::class)->syncForAgent($model, $plaintext);
        } catch (\Throwable $e) {
            Log::warning('plugin package rebuild failed', ['agent_id' => $model->id, 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'key' => $plaintext]);
    }

    /**
     * @param  Collection<int, Agent>  $agents
     */
    private function respondWithAgents($agents): JsonResponse
    {
        $knowledge = KnowledgeEntry::query()
            ->whereIn('updated_by', $agents->pluck('name'))
            ->selectRaw('updated_by, count(*) as aggregate')
            ->groupBy('updated_by')
            ->pluck('aggregate', 'updated_by');

        return response()->json([
            'ok' => true,
            'agents' => $agents
                ->map(fn (Agent $agent): array => $this->agentPayload($agent, (int) ($knowledge[$agent->name] ?? 0)))
                ->values()
                ->all(),
        ]);
    }

    /**
     * The wire shape of one brain, shared by the list and detail
     * endpoints. Last activity is the newest file write or memory
     * entry, whichever came later.
     *
     * @return array<string, mixed>
     */
    private function agentPayload(Agent $agent, int $knowledge): array
    {
        $lastMemoryAt = collect($agent->memories ?? [])->max('at');

        $lastActivity = collect([
            $agent->files_max_updated_at !== null ? Carbon::parse($agent->files_max_updated_at) : null,
            is_string($lastMemoryAt) ? Carbon::parse($lastMemoryAt) : null,
        ])->filter()->max();

        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'slug' => $this->slug($agent),
            'status' => $agent->status,
            'counts' => [
                'memories' => count($agent->memories ?? []),
                'files' => (int) $agent->files_count,
                'knowledge' => $knowledge,
            ],
            'last_activity' => $lastActivity?->toIso8601String(),
            'created_at' => $agent->created_at?->toIso8601String(),
        ];
    }

    private function slug(Agent $agent): string
    {
        return Str::slug($agent->name) ?: 'agent';
    }

    private function knowledgeCount(string $agentName): int
    {
        return (int) KnowledgeEntry::query()->where('updated_by', $agentName)->count();
    }

    /**
     * Enforce an endpoint permission on the resolved admin key. Returns
     * the 403 response when denied, null when the call may proceed.
     */
    private function permit(Request $request, string $permission): ?JsonResponse
    {
        $key = $request->attributes->get('admin_key');

        if ($key instanceof AdminApiKey && ! $key->allows($permission)) {
            return $this->fail("This key lacks the {$permission} permission.", 403);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function fail(string $error, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $error, ...$extra], $status);
    }
}
