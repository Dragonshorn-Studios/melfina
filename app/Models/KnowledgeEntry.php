<?php

namespace App\Models;

use Database\Factories\KnowledgeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One canonical knowledge entry — an install-wide record of something the
 * internal world calls by name: a project, service, decision, convention,
 * runbook, person, or concept. Shared across every agent on the install;
 * provenance is recorded per write on `updated_by` and in versions.
 *
 * @property string $name Canonical name.
 * @property string $name_lower Lowercased name — the unique lookup key.
 * @property string $type One of self::TYPES.
 * @property array<int, string> $aliases
 * @property string $summary Short one-liner.
 * @property string $state Current state and important constraints.
 * @property array<int, string> $related Canonical names of related entries (legacy; graph links are source of truth).
 * @property string|null $source Where the knowledge came from.
 * @property Carbon|null $verified_at
 * @property string $updated_by Agent name (or 'owner') of the last writer.
 * @property int $version Bumped on every write; versions holds history.
 */
#[Fillable(['name', 'name_lower', 'type', 'aliases', 'summary', 'state', 'related', 'source', 'verified_at', 'updated_by', 'version'])]
class KnowledgeEntry extends Model
{
    /** @use HasFactory<KnowledgeEntryFactory> */
    use HasFactory;

    public const TYPES = [
        'project',
        'service',
        'decision',
        'convention',
        'runbook',
        'person',
        'concept',
        'event',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'related' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeEntryVersion::class);
    }

    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(KnowledgeLink::class, 'from_entry_id');
    }

    public function incomingLinks(): HasMany
    {
        return $this->hasMany(KnowledgeLink::class, 'to_entry_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(KnowledgeObservation::class, 'entry_id');
    }

    /**
     * Cheap deterministic lookup: exact canonical name first, then
     * case-insensitive, then aliases (exact, then case-insensitive).
     * Returns null when nothing internal answers the name.
     */
    public static function lookup(string $name): ?self
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $exact = static::query()->where('name', $name)->first();

        if ($exact !== null) {
            return $exact;
        }

        $lower = Str::lower($name);

        $ci = static::query()->where('name_lower', $lower)->first();

        if ($ci !== null) {
            return $ci;
        }

        // Aliases live in a JSON column — LIKE narrows to candidate rows
        // (an unescaped %/_ in the needle can only over-match, never miss),
        // and the PHP pass does the actual case-insensitive compare.
        return static::query()
            ->where('aliases', 'like', '%'.$name.'%')
            ->get()
            ->first(fn (self $entry) => collect($entry->aliases)
                ->contains(fn (string $alias) => Str::lower($alias) === $lower));
    }

    /**
     * Persist the current fields as a version snapshot. Called inside the
     * same write as the entry save so history can never be skipped.
     */
    public function recordVersion(string $author): KnowledgeEntryVersion
    {
        return $this->versions()->create([
            'version' => $this->version,
            'author' => $author,
            'snapshot' => [
                'name' => $this->name,
                'type' => $this->type,
                'aliases' => $this->aliases,
                'summary' => $this->summary,
                'state' => $this->state,
                'related' => $this->related,
                'source' => $this->source,
                'verified_at' => $this->verified_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Related entries that still resolve, as name → summary pairs. Names
     * that no longer match an entry are reported under 'unresolved' so a
     * stale link is visible instead of silently dropped.
     *
     * @return array{entries: Collection<int, array{name: string, summary: string}>, unresolved: array<int, string>}
     */
    public function resolvedRelated(): array
    {
        $fromLinks = $this->outgoingLinks()
            ->where('status', KnowledgeLinkStatus::Active)
            ->with('toEntry')
            ->get()
            ->map(fn (KnowledgeLink $link) => [
                'name' => $link->toEntry->name,
                'summary' => $link->toEntry->summary,
            ]);

        $fromIncoming = $this->incomingLinks()
            ->where('status', KnowledgeLinkStatus::Active)
            ->with('fromEntry')
            ->get()
            ->map(fn (KnowledgeLink $link) => [
                'name' => $link->fromEntry->name,
                'summary' => $link->fromEntry->summary,
            ]);

        $fromGraph = $fromLinks->concat($fromIncoming)->unique('name')->values();

        $related = collect($this->related ?? [])
            ->map(fn (string $n) => trim($n))
            ->filter()
            ->unique()
            ->values();

        $inGraph = $fromGraph->pluck('name')->map(fn (string $n) => Str::lower($n));
        $leftover = $related
            ->reject(fn (string $n) => $inGraph->contains(Str::lower($n)))
            ->values();

        $found = $leftover->isEmpty()
            ? collect()
            : static::query()
                ->whereIn('name_lower', $leftover->map(fn (string $n) => Str::lower($n)))
                ->get(['name', 'summary']);

        $foundLower = $found->pluck('name')->map(fn (string $n) => Str::lower($n));

        return [
            'entries' => $fromGraph
                ->concat($found->map(fn (self $entry) => [
                    'name' => $entry->name,
                    'summary' => $entry->summary,
                ]))
                ->unique('name')
                ->values(),
            'unresolved' => $leftover
                ->reject(fn (string $n) => $foundLower->contains(Str::lower($n)))
                ->values()
                ->all(),
        ];
    }
}
