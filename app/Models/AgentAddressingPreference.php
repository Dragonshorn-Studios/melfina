<?php

namespace App\Models;

use Database\Factories\AgentAddressingPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Optional fallback for how an agent may address a person when no
 * explicit instruction exists. Never a global alias, never injected as
 * an instruction layer, and never returned as a command from lookup.
 *
 * @property string $context
 * @property string $form
 * @property string $author
 */
#[Fillable(['agent_id', 'person_entry_id', 'context', 'form', 'author'])]
class AgentAddressingPreference extends Model
{
    /** @use HasFactory<AgentAddressingPreferenceFactory> */
    use HasFactory;

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'person_entry_id');
    }
}
