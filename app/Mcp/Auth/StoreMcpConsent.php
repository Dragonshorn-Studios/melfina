<?php

namespace App\Mcp\Auth;

use App\Models\Agent;
use App\Models\McpPendingGrant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;

class StoreMcpConsent
{
    /**
     * Persist which agent the approved OAuth client may act as. Runs inside
     * the approve request, before the auth code is issued — a token minted
     * without a grant here resolves to no agent and fails closed.
     */
    public function handle(Request $request): void
    {
        $user = $request->user();
        $clientId = $request->input('client_id');

        if (! $user instanceof User || ! is_string($clientId) || $clientId === '') {
            return;
        }

        if (Client::query()->whereKey($clientId)->first() === null) {
            return;
        }

        $agentId = $request->input('agent_id');

        if (! is_scalar($agentId)) {
            throw ValidationException::withMessages([
                'agent_id' => 'Select the agent this client may act as.',
            ]);
        }

        $agent = Agent::query()->whereKey((int) $agentId)->first();

        if ($agent === null) {
            throw ValidationException::withMessages([
                'agent_id' => 'The selected agent does not exist.',
            ]);
        }

        McpPendingGrant::query()->updateOrCreate(
            ['user_id' => $user->id, 'client_id' => $clientId],
            ['agent_id' => $agent->id],
        );
    }
}
