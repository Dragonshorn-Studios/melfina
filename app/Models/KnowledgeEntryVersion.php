<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable snapshot of a knowledge entry — written on every upsert so
 * an agent's change is never a silent overwrite of canonical history.
 *
 * @property int $version
 * @property array{name: string, type: string, aliases: array<int, string>, summary: string, state: string, related: array<int, string>, source: string|null, verified_at: string|null} $snapshot
 * @property string $author Agent name (or 'owner') that wrote the version.
 */
#[Fillable(['knowledge_entry_id', 'version', 'snapshot', 'author'])]
class KnowledgeEntryVersion extends Model
{
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'knowledge_entry_id');
    }
}
