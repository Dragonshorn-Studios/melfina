<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The owner's consent choice for an OAuth client: which agent tokens from
 * this client bind to. Written when the authorize form is approved and
 * consumed by AttachTokenAgent whenever an access token is issued.
 */
#[Fillable(['user_id', 'client_id', 'agent_id'])]
class McpPendingGrant extends Model
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
