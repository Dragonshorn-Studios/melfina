<?php

namespace App\Models;

use App\Services\KnowledgeIdentityResolver;
use Database\Factories\KnowledgeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    public function identifiers(): HasMany
    {
        return $this->hasMany(KnowledgeIdentifier::class, 'entry_id');
    }

    /**
     * JSON `aliases` is a compatibility projection of kind=alias
     * identifier rows. Keep the column in lockstep whenever those rows
     * change so the console form and legacy readers do not drop them.
     */
    public function syncAliasProjection(): void
    {
        $projected = $this->identifiers()
            ->where('kind', KnowledgeIdentifierKind::Alias)
            ->orderBy('id')
            ->pluck('value')
            ->values()
            ->all();

        static::query()->whereKey($this->id)->update([
            'aliases' => json_encode($projected),
        ]);
        $this->aliases = $projected;
    }

    public function entityReferences(): HasMany
    {
        return $this->hasMany(AgentEntityReference::class, 'entry_id');
    }

    public function redirect(): HasOne
    {
        return $this->hasOne(KnowledgeEntryRedirect::class, 'from_entry_id');
    }

    /**
     * Global-only compatibility wrapper. Agent-facing tools must pass
     * explicit context to {@see KnowledgeIdentityResolver} instead of
     * reading the current agent from request state here.
     */
    public static function lookup(string $name): ?self
    {
        return app(KnowledgeIdentityResolver::class)->lookupGlobal($name);
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
     * @return array{entries: Collection<int, array{entry_id?: int, name: string, summary: string}>, unresolved: array<int, string>}
     */
    public function resolvedRelated(): array
    {
        $fromLinks = $this->outgoingLinks()
            ->where('status', KnowledgeLinkStatus::Active)
            ->with('toEntry')
            ->get()
            ->map(fn (KnowledgeLink $link) => [
                'entry_id' => $link->toEntry->id,
                'name' => $link->toEntry->name,
                'summary' => $link->toEntry->summary,
            ]);

        $fromIncoming = $this->incomingLinks()
            ->where('status', KnowledgeLinkStatus::Active)
            ->with('fromEntry')
            ->get()
            ->map(fn (KnowledgeLink $link) => [
                'entry_id' => $link->fromEntry->id,
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
                    'entry_id' => $entry->id,
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
