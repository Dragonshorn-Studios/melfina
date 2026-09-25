<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Binds an issued OAuth access token (Passport jti) to the agent it may
 * act as. Created by AttachTokenAgent on AccessTokenCreated; resolved by
 * the ResolveAgent middleware on each MCP call.
 */
#[Fillable(['token_id', 'client_id', 'user_id', 'agent_id'])]
class McpTokenAgent extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
