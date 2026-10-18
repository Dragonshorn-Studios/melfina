<?php

namespace App\Models;

use Database\Factories\KnowledgeIdentifierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A confirmed global name of a knowledge entity — alias, handle, or
 * human persona. Distinct from {@see Agent::$persona} and from
 * per-agent contextual references.
 *
 * @property string $value
 * @property string $normalized_value
 * @property KnowledgeIdentifierKind $kind
 * @property string $author
 */
#[Fillable(['entry_id', 'value', 'normalized_value', 'kind', 'author'])]
class KnowledgeIdentifier extends Model
{
    /** @use HasFactory<KnowledgeIdentifierFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => KnowledgeIdentifierKind::class,
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_id');
    }
}
