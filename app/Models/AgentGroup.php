<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A set of agents that share an instruction preamble, a main person,
 * and shared-space grants.
 *
 * @property string $name
 * @property string|null $preamble
 * @property int|null $main_person_entry_id
 */
#[Fillable(['name', 'preamble', 'main_person_entry_id'])]
class AgentGroup extends Model
{
    public function mainPerson(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'main_person_entry_id');
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_group_agent')->withTimestamps();
    }
}
