<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner grant of a shared space to every agent in a group.
 * 'write' implies 'read'. A direct agent grant still wins when present.
 *
 * @property string $permission
 */
#[Fillable(['shared_space_id', 'agent_group_id', 'permission'])]
class SharedGroupAcl extends Model
{
    protected $table = 'shared_group_acl';

    public function sharedSpace(): BelongsTo
    {
        return $this->belongsTo(SharedSpace::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AgentGroup::class, 'agent_group_id');
    }
}
