<?php

namespace App\Models;

use Database\Factories\KnowledgeObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only event that evidenced an entity write or a link assert/retract.
 * Confidence is derived from these rows, never stored as an agent-supplied score.
 *
 * @property KnowledgeObservationKind $kind
 * @property string $author
 * @property KnowledgeAuthorKind $author_kind
 * @property array<string, mixed> $payload
 * @property string|null $source
 * @property Carbon|null $occurred_at
 */
#[Fillable([
    'kind',
    'entry_id',
    'link_id',
    'author',
    'author_kind',
    'payload',
    'source',
    'occurred_at',
])]
class KnowledgeObservation extends Model
{
    /** @use HasFactory<KnowledgeObservationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => KnowledgeObservationKind::class,
            'author_kind' => KnowledgeAuthorKind::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(KnowledgeLink::class, 'link_id');
    }
}
