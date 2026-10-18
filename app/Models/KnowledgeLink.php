<?php

namespace App\Models;

use Database\Factories\KnowledgeLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A typed directed edge between two knowledge entities. Confidence is
 * derived from observations and cached here so lookup does not scan the log.
 *
 * @property KnowledgePredicate $predicate
 * @property KnowledgeLinkStatus $status
 * @property int $confidence Milli-confidence 0–1000.
 * @property int $observation_count
 * @property bool $disputed
 * @property bool $permanent Owner-locked; agents cannot retract.
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 */
#[Fillable([
    'from_entry_id',
    'to_entry_id',
    'predicate',
    'status',
    'confidence',
    'observation_count',
    'disputed',
    'permanent',
    'first_seen_at',
    'last_seen_at',
])]
class KnowledgeLink extends Model
{
    /** @use HasFactory<KnowledgeLinkFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'predicate' => KnowledgePredicate::class,
            'status' => KnowledgeLinkStatus::class,
            'disputed' => 'boolean',
            'permanent' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function fromEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'from_entry_id');
    }

    public function toEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'to_entry_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(KnowledgeObservation::class, 'link_id');
    }

    public function isActive(): bool
    {
        return $this->status === KnowledgeLinkStatus::Active;
    }
}
