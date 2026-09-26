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
 * @property array<int, string> $related Canonical names of related entries.
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
        $related = collect($this->related ?? []);

        if ($related->isEmpty()) {
            return ['entries' => collect(), 'unresolved' => []];
        }

        $found = static::query()
            ->whereIn('name_lower', $related->map(fn (string $n) => Str::lower($n)))
            ->pluck('summary', 'name');

        return [
            'entries' => $found->map(fn (string $summary, string $name) => [
                'name' => $name,
                'summary' => $summary,
            ])->values(),
            'unresolved' => $related
                ->reject(fn (string $n) => $found->keys()->contains(fn (string $k) => Str::lower($k) === Str::lower($n)))
                ->values()
                ->all(),
        ];
    }
}
