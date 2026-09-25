<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A named shared folder — the only cross-agent bridge in Melfina.
 *
 * @property string $name
 */
#[Fillable(['name'])]
class SharedSpace extends Model
{
    use HasFactory;

    public function aclEntries(): HasMany
    {
        return $this->hasMany(SharedAcl::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(SharedFile::class);
    }

    public function permissionFor(Agent $agent): ?string
    {
        return $this->aclEntries()->where('agent_id', $agent->id)->value('permission');
    }

    public function canRead(Agent $agent): bool
    {
        return $this->permissionFor($agent) !== null;
    }

    public function canWrite(Agent $agent): bool
    {
        return $this->permissionFor($agent) === SharedAcl::PERMISSION_WRITE;
    }

    /**
     * Spaces the agent may read (read or write grants both count).
     */
    public static function readableBy(Agent $agent): Collection
    {
        return static::query()
            ->whereHas('aclEntries', fn ($q) => $q->where('agent_id', $agent->id))
            ->orderBy('name')
            ->pluck('name');
    }
}
