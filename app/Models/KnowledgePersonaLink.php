<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Undirected equality between two person entries: the same living being,
 * two presentations.
 *
 * @property int $entry_low_id
 * @property int $entry_high_id
 */
#[Fillable(['entry_low_id', 'entry_high_id'])]
class KnowledgePersonaLink extends Model
{
    public function low(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_low_id');
    }

    public function high(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_high_id');
    }
}
