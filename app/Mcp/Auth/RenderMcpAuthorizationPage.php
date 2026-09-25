<?php

namespace App\Mcp\Auth;

use App\Models\Agent;
use App\Models\McpPendingGrant;
use App\Models\User;
use Illuminate\Http\Response;

class RenderMcpAuthorizationPage
{
    /**
     * Render the owner consent screen: which agent this OAuth client may
     * act as. An earlier grant for the same client preselects its agent.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function handle(array $parameters): Response
    {
        $agents = Agent::query()->orderBy('name')->get();

        $selectedAgentId = null;
        $user = $parameters['user'] ?? null;
        $client = $parameters['client'] ?? null;

        if ($user instanceof User && $client !== null) {
            $grant = McpPendingGrant::query()
                ->where('user_id', $user->id)
                ->where('client_id', $client->getKey())
                ->first();

            $selectedAgentId = $grant?->agent_id;
        }

        return response()->view('mcp.authorize', [
            ...$parameters,
            'agents' => $agents,
            'selectedAgentId' => $selectedAgentId,
        ]);
    }
}
