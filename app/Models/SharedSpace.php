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

    public function groupAclEntries(): HasMany
    {
        return $this->hasMany(SharedGroupAcl::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(SharedFile::class);
    }

    public function permissionFor(Agent $agent): ?string
    {
        $direct = $this->aclEntries()->where('agent_id', $agent->id)->value('permission');

        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        $groupIds = $agent->groups()->pluck('agent_groups.id');
        if ($groupIds->isEmpty()) {
            return null;
        }

        $groupPermissions = $this->groupAclEntries()
            ->whereIn('agent_group_id', $groupIds)
            ->pluck('permission');

        if ($groupPermissions->contains(SharedAcl::PERMISSION_WRITE)) {
            return SharedAcl::PERMISSION_WRITE;
        }

        if ($groupPermissions->contains(SharedAcl::PERMISSION_READ)) {
            return SharedAcl::PERMISSION_READ;
        }

        return null;
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
        $groupIds = $agent->groups()->pluck('agent_groups.id');

        return static::query()
            ->where(function ($query) use ($agent, $groupIds) {
                $query->whereHas('aclEntries', fn ($q) => $q->where('agent_id', $agent->id));

                if ($groupIds->isNotEmpty()) {
                    $query->orWhereHas('groupAclEntries', fn ($q) => $q->whereIn('agent_group_id', $groupIds));
                }
            })
            ->orderBy('name')
            ->pluck('name');
    }
}
