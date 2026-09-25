<?php

namespace App\Mcp\Auth;

use App\Models\McpPendingGrant;
use App\Models\McpTokenAgent;
use Laravel\Passport\Events\AccessTokenCreated;

class AttachTokenAgent
{
    /**
     * Bind each issued access token to the agent the owner picked on the
     * consent screen. Tokens issued without a pending grant get no binding
     * and resolve to no agent — they fail closed on MCP calls.
     */
    public function handle(AccessTokenCreated $event): void
    {
        if ($event->userId === null) {
            return;
        }

        $grant = McpPendingGrant::query()
            ->where('user_id', $event->userId)
            ->where('client_id', $event->clientId)
            ->first();

        if ($grant === null) {
            return;
        }

        McpTokenAgent::query()->updateOrCreate(
            ['token_id' => $event->tokenId],
            [
                'client_id' => $event->clientId,
                'user_id' => $event->userId,
                'agent_id' => $grant->agent_id,
            ],
        );
    }
}
