<?php

namespace App\Models;

use Database\Factories\KnowledgeEntryRedirectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit owner-approved redirect from a duplicate entity id to the
 * canonical person. Historical rows stay on the source id; reads follow
 * the redirect. New writes must target the canonical id.
 *
 * @property int $from_entry_id
 * @property int $to_entry_id
 * @property string $author
 */
#[Fillable(['from_entry_id', 'to_entry_id', 'author'])]
class KnowledgeEntryRedirect extends Model
{
    /** @use HasFactory<KnowledgeEntryRedirectFactory> */
    use HasFactory;

    public function fromEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'from_entry_id');
    }

    public function toEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'to_entry_id');
    }
}
