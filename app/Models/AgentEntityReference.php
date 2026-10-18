<?php

namespace App\Models;

use Database\Factories\AgentEntityReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An owner-managed name that resolves only for one agent. "Szefowa" is a
 * title in that imp's context, not a global alias of the person.
 *
 * @property string $value
 * @property string $normalized_value
 * @property AgentEntityReferenceKind $kind
 * @property string $author
 */
#[Fillable(['agent_id', 'entry_id', 'value', 'normalized_value', 'kind', 'author'])]
class AgentEntityReference extends Model
{
    /** @use HasFactory<AgentEntityReferenceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => AgentEntityReferenceKind::class,
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_id');
    }
}
