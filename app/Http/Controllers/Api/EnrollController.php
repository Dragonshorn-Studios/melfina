<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentApiKey;
use App\Models\AgentEnrollKey;
use App\Models\AgentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The unauthenticated enrollment door: an agent runtime with no secret
 * storage reports in with a live `melfe_…` enrollment key plus its own
 * `id` and claimed `name`. The report lands on the owner's accept list;
 * once accepted, every call mints a fresh short-lived scoped key the
 * agent writes to its own file. Revoked enrollments get nothing.
 *
 * Fails closed and says as little as possible: a bad key is a bare 401,
 * and only a valid key learns whether a report is pending/accepted.
 */
class EnrollController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = $request->string('secret')->toString();

        if ($secret === '' || ! AgentEnrollKey::isLiveKey($secret)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $validated = $request->validate([
            'id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:@-]+$/'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        // A leaked enroll key can still only spam pending rows, never
        // mint — but cap the queue so it can't grow the table forever.
        if (AgentEnrollment::query()
            ->where('remote_id', $validated['id'])
            ->doesntExist()
            && AgentEnrollment::query()->where('status', AgentEnrollment::STATUS_PENDING)->count() >= 100) {
            return response()->json(['ok' => false, 'error' => 'pending list full'], 429);
        }

        $enrollment = AgentEnrollment::query()->firstOrCreate(
            ['remote_id' => $validated['id']],
            ['name' => $validated['name'], 'status' => AgentEnrollment::STATUS_PENDING],
        );

        // A still-pending report carries its latest claimed name; rows the
        // owner already acted on keep the record they decided over.
        if ($enrollment->isPending() && $enrollment->name !== $validated['name']) {
            $enrollment->update(['name' => $validated['name']]);
        }

        if ($enrollment->isPending()) {
            return response()->json(['ok' => true, 'status' => 'pending'], 202);
        }

        $agent = $enrollment->agent;

        if (! $enrollment->isAccepted() || $agent === null || ! $agent->isActive()) {
            return response()->json(
                ['ok' => false, 'status' => $enrollment->status, 'error' => 'enrollment not accepted'],
                403,
            );
        }

        $key = AgentApiKey::issueMinted(
            $agent,
            'enroll:'.Str::limit($enrollment->remote_id, 40, ''),
            $enrollment->scopes ?? [],
            $enrollment,
        );

        return response()->json([
            'ok' => true,
            'status' => 'accepted',
            'agent' => $agent->name,
            'key' => $key,
            'expires_at' => now()->addSeconds(AgentApiKey::MINTED_TTL_SECONDS)->toIso8601String(),
        ]);
    }
}
