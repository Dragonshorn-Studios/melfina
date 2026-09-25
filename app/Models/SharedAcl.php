<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner grant of access to a shared space for one agent.
 * 'write' implies 'read'.
 *
 * @property string $permission
 */
#[Fillable(['shared_space_id', 'agent_id', 'permission'])]
class SharedAcl extends Model
{
    use HasFactory;

    public const PERMISSION_READ = 'read';

    public const PERMISSION_WRITE = 'write';

    protected $table = 'shared_acl';

    public function sharedSpace(): BelongsTo
    {
        return $this->belongsTo(SharedSpace::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
