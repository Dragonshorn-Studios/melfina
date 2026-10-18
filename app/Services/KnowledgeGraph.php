<?php

namespace App\Services;

use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeLink;
use App\Models\KnowledgeLinkStatus;
use App\Models\KnowledgeObservation;
use App\Models\KnowledgeObservationKind;
use App\Models\KnowledgePredicate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Install-wide world graph: typed links between canonical knowledge
 * entities, with an append-only observation log as the source of truth
 * for confidence. Agents never pass a score; the owner can lock an edge
 * as permanent so agents cannot retract it.
 */
class KnowledgeGraph
{
    public const CONFIDENCE_OWNER_ASSERT = 900;

    public const CONFIDENCE_OWNER_FLOOR = 950;

    public const CONFIDENCE_PER_AGENT = 220;

    public const CONFIDENCE_PER_REPEAT = 30;

    public const REPEAT_CAP_PER_AGENT = 3;

    public const CONFIDENCE_DISPUTE = 250;

    public const CONFIDENCE_MAX = 990;

    /**
     * Assert or retract a typed link. Missing endpoints do not auto-create
     * unless `$createMissing` is true — typos must not spawn nodes.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function connect(
        string $author,
        KnowledgeAuthorKind $authorKind,
        string $from,
        string $to,
        KnowledgePredicate $predicate,
        KnowledgeObservationKind $op = KnowledgeObservationKind::LinkAssert,
        ?string $note = null,
        ?array $data = null,
        ?string $source = null,
        ?Carbon $occurredAt = null,
        bool $createMissing = false,
        ?string $eventName = null,
    ): KnowledgeConnectResult {
        if ($op !== KnowledgeObservationKind::LinkAssert && $op !== KnowledgeObservationKind::LinkRetract) {
            return new KnowledgeConnectResult(ok: false, error: 'op must be link_assert or link_retract.');
        }

        $fromEntry = $this->resolveEndpoint($from, $createMissing, $author);
        $toEntry = $this->resolveEndpoint($to, $createMissing, $author);

        $unresolved = [];
        if ($fromEntry === null) {
            $unresolved['from'] = $from;
        }
        if ($toEntry === null) {
            $unresolved['to'] = $to;
        }
        if ($unresolved !== []) {
            return new KnowledgeConnectResult(ok: false, unresolved: $unresolved);
        }

        if ($fromEntry->is($toEntry)) {
            return new KnowledgeConnectResult(ok: false, error: 'A link cannot point an entity at itself.');
        }

        if ($predicate->swapsEndpoints()) {
            [$fromEntry, $toEntry] = [$toEntry, $fromEntry];
        }
        $stored = $predicate->stored();

        return DB::transaction(function () use (
            $author,
            $authorKind,
            $fromEntry,
            $toEntry,
            $stored,
            $op,
            $note,
            $data,
            $source,
            $occurredAt,
            $eventName,
        ): KnowledgeConnectResult {
            $link = KnowledgeLink::query()->firstOrNew([
                'from_entry_id' => $fromEntry->id,
                'to_entry_id' => $toEntry->id,
                'predicate' => $stored,
            ]);

            $created = ! $link->exists;

            if ($op === KnowledgeObservationKind::LinkRetract && $created) {
                return new KnowledgeConnectResult(
                    ok: false,
                    error: 'No such link to retract.',
                );
            }

            if ($op === KnowledgeObservationKind::LinkRetract
                && $link->permanent
                && $authorKind === KnowledgeAuthorKind::Agent) {
                return new KnowledgeConnectResult(
                    ok: false,
                    link: $link,
                    error: 'This link is permanent — only the owner can retract it.',
                );
            }

            if ($created) {
                $link->status = KnowledgeLinkStatus::Active;
                $link->confidence = 0;
                $link->observation_count = 0;
                $link->disputed = false;
                $link->permanent = false;
                $link->first_seen_at = now();
                $link->last_seen_at = now();
                $link->save();
            }

            $observation = $this->recordObservation(
                $link,
                $fromEntry,
                $op,
                $author,
                $authorKind,
                [
                    'from' => $fromEntry->name,
                    'to' => $toEntry->name,
                    'predicate' => $stored->value,
                    'note' => $note,
                    'data' => $data ?? [],
                    'event' => $eventName,
                ],
                $source,
                $occurredAt,
            );

            $this->recompute($link);

            if ($eventName !== null && $eventName !== '' && $op === KnowledgeObservationKind::LinkAssert) {
                $this->attachNamedEvent($eventName, $fromEntry, $toEntry, $author, $authorKind, $source, $occurredAt);
            }

            return new KnowledgeConnectResult(
                ok: true,
                link: $link->fresh(['fromEntry', 'toEntry']),
                observation: $observation,
                created: $created,
            );
        });
    }

    /**
     * Owner attests the link is true — jumps confidence to the owner floor.
     */
    public function confirm(KnowledgeLink $link, string $author = 'owner'): KnowledgeLink
    {
        return $this->ownerStamp($link, $author, KnowledgeObservationKind::OwnerConfirm);
    }

    /**
     * Owner locks (or unlocks) the edge. Permanent links sit at the owner
     * confidence floor and reject agent retracts.
     */
    public function setPermanent(KnowledgeLink $link, bool $permanent, string $author = 'owner'): KnowledgeLink
    {
        $kind = $permanent
            ? KnowledgeObservationKind::OwnerPermanent
            : KnowledgeObservationKind::OwnerUnpermanent;

        return $this->ownerStamp($link, $author, $kind);
    }

    /**
     * Owner-only predicate/endpoint fix. Does not bump the entity wiki version.
     */
    public function retarget(
        KnowledgeLink $link,
        KnowledgePredicate $predicate,
        KnowledgeEntry $from,
        KnowledgeEntry $to,
        string $author = 'owner',
    ): KnowledgeConnectResult {
        if ($from->is($to)) {
            return new KnowledgeConnectResult(ok: false, error: 'A link cannot point an entity at itself.');
        }

        if ($predicate->swapsEndpoints()) {
            [$from, $to] = [$to, $from];
        }
        $stored = $predicate->stored();

        return DB::transaction(function () use ($link, $stored, $from, $to, $author): KnowledgeConnectResult {
            $collision = KnowledgeLink::query()
                ->where('from_entry_id', $from->id)
                ->where('to_entry_id', $to->id)
                ->where('predicate', $stored)
                ->whereKeyNot($link->id)
                ->first();

            if ($collision !== null) {
                return new KnowledgeConnectResult(
                    ok: false,
                    link: $collision,
                    error: 'That from/to/predicate already exists.',
                );
            }

            $link->from_entry_id = $from->id;
            $link->to_entry_id = $to->id;
            $link->predicate = $stored;
            $link->save();

            $this->recordObservation(
                $link,
                $from,
                KnowledgeObservationKind::LinkFix,
                $author,
                KnowledgeAuthorKind::Owner,
                [
                    'from' => $from->name,
                    'to' => $to->name,
                    'predicate' => $stored->value,
                ],
            );

            $this->recompute($link);

            return new KnowledgeConnectResult(ok: true, link: $link->fresh(['fromEntry', 'toEntry']));
        });
    }

    /**
     * 1-hop neighborhood. Incoming rows report the inverse predicate name
     * so an agent reading "Botbox" sees `hosts` rather than `runs_on`.
     *
     * @return array{outgoing: list<array<string, mixed>>, incoming: list<array<string, mixed>>}
     */
    public function neighborhood(KnowledgeEntry $entry, int $minConfidence = 0, bool $includeRetracted = false): array
    {
        $outgoing = KnowledgeLink::query()
            ->with('toEntry')
            ->where('from_entry_id', $entry->id)
            ->when(! $includeRetracted, fn ($q) => $q->where('status', KnowledgeLinkStatus::Active))
            ->where('confidence', '>=', $minConfidence)
            ->get()
            ->map(fn (KnowledgeLink $link) => $this->serializeHop($link, outgoing: true))
            ->values()
            ->all();

        $incoming = KnowledgeLink::query()
            ->with('fromEntry')
            ->where('to_entry_id', $entry->id)
            ->when(! $includeRetracted, fn ($q) => $q->where('status', KnowledgeLinkStatus::Active))
            ->where('confidence', '>=', $minConfidence)
            ->get()
            ->map(fn (KnowledgeLink $link) => $this->serializeHop($link, outgoing: false))
            ->values()
            ->all();

        return ['outgoing' => $outgoing, 'incoming' => $incoming];
    }

    /**
     * Bounded walk for lookup `hops` > 1. Cycles are skipped; retracted
     * edges are omitted. Node cap keeps the payload agent-sized.
     *
     * @return list<array{from: string, to: string, predicate: string, confidence: int, hops: int}>
     */
    public function walk(KnowledgeEntry $entry, int $hops = 2, int $minConfidence = 0, int $nodeCap = 25): array
    {
        $hops = max(1, min(2, $hops));
        $seen = [$entry->id => true];
        $frontier = [$entry->id];
        $edges = [];

        for ($depth = 1; $depth <= $hops; $depth++) {
            $next = [];

            $links = KnowledgeLink::query()
                ->with(['fromEntry', 'toEntry'])
                ->where('status', KnowledgeLinkStatus::Active)
                ->where('confidence', '>=', $minConfidence)
                ->where(function ($q) use ($frontier) {
                    $q->whereIn('from_entry_id', $frontier)
                        ->orWhereIn('to_entry_id', $frontier);
                })
                ->get();

            foreach ($links as $link) {
                $fromId = $link->from_entry_id;
                $toId = $link->to_entry_id;
                $peerId = in_array($fromId, $frontier, true) ? $toId : $fromId;

                $edges[] = [
                    'from' => $link->fromEntry->name,
                    'to' => $link->toEntry->name,
                    'predicate' => $link->predicate->value,
                    'confidence' => $link->confidence,
                    'permanent' => $link->permanent,
                    'hops' => $depth,
                ];

                if (! isset($seen[$peerId])) {
                    $seen[$peerId] = true;
                    $next[] = $peerId;
                }

                if (count($seen) >= $nodeCap) {
                    return $edges;
                }
            }

            $frontier = $next;
            if ($frontier === []) {
                break;
            }
        }

        return $edges;
    }

    /**
     * Full constellation payload for the owner graph view.
     *
     * @return array{nodes: list<array<string, mixed>>, links: list<array<string, mixed>>}
     */
    public function constellation(?int $focusId = null, int $hops = 2, int $limit = 250): array
    {
        $entries = KnowledgeEntry::query()->orderBy('name_lower')->get();
        $links = KnowledgeLink::query()
            ->where('status', KnowledgeLinkStatus::Active)
            ->get();

        if ($focusId !== null && $entries->count() > $limit) {
            $keep = $this->focusIds($focusId, $hops, $limit);
            $entries = $entries->whereIn('id', $keep)->values();
            $links = $links->filter(
                fn (KnowledgeLink $link) => isset($keep[$link->from_entry_id]) && isset($keep[$link->to_entry_id]),
            )->values();
        } elseif ($entries->count() > $limit) {
            $entries = $entries->take($limit)->values();
            $ids = $entries->pluck('id')->all();
            $links = $links->filter(
                fn (KnowledgeLink $link) => in_array($link->from_entry_id, $ids, true)
                    && in_array($link->to_entry_id, $ids, true),
            )->values();
        }

        return [
            'nodes' => $entries->map(fn (KnowledgeEntry $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'type' => $e->type,
                'summary' => $e->summary,
            ])->values()->all(),
            'links' => $links->map(fn (KnowledgeLink $link) => [
                'id' => $link->id,
                'from' => $link->from_entry_id,
                'to' => $link->to_entry_id,
                'predicate' => $link->predicate->value,
                'confidence' => $link->confidence,
                'permanent' => $link->permanent,
                'disputed' => $link->disputed,
            ])->values()->all(),
        ];
    }

    /**
     * Recompute cached confidence from the observation log. Integer 0–1000.
     */
    public function recompute(KnowledgeLink $link): KnowledgeLink
    {
        $observations = $link->observations()->orderBy('id')->get();

        $asserts = [];
        $retracts = [];
        $kinds = [];
        $permanent = false;
        $ownerRetracted = false;

        foreach ($observations as $obs) {
            $kinds[$obs->author] = $obs->author_kind;
            $isOwner = $obs->author_kind === KnowledgeAuthorKind::Owner;

            if (in_array($obs->kind, [
                KnowledgeObservationKind::LinkAssert,
                KnowledgeObservationKind::OwnerConfirm,
                KnowledgeObservationKind::OwnerPermanent,
                KnowledgeObservationKind::LinkFix,
            ], true)) {
                $asserts[$obs->author] = ($asserts[$obs->author] ?? 0) + 1;
                if ($isOwner) {
                    $ownerRetracted = false;
                }
            }

            if ($obs->kind === KnowledgeObservationKind::LinkRetract) {
                $retracts[$obs->author] = ($retracts[$obs->author] ?? 0) + 1;
                if ($isOwner) {
                    $ownerRetracted = true;
                    $permanent = false;
                }
            }

            if ($obs->kind === KnowledgeObservationKind::OwnerPermanent) {
                $permanent = true;
            }
            if ($obs->kind === KnowledgeObservationKind::OwnerUnpermanent) {
                $permanent = false;
            }
        }

        $link->permanent = $permanent;
        $link->observation_count = $observations->count();
        $link->last_seen_at = now();
        $link->first_seen_at ??= $link->created_at ?? now();

        if ($ownerRetracted || $observations->isEmpty()) {
            $link->status = KnowledgeLinkStatus::Retracted;
            $link->confidence = 0;
            $link->disputed = false;
            $link->save();

            return $link;
        }

        $ownerPositive = false;
        $ownerConfirmed = $observations->contains(
            fn (KnowledgeObservation $o) => in_array($o->kind, [
                KnowledgeObservationKind::OwnerConfirm,
                KnowledgeObservationKind::OwnerPermanent,
            ], true),
        );

        $agentCount = 0;
        $repeats = 0;
        $disputeAuthors = 0;
        $anyonePositive = false;

        foreach ($kinds as $author => $kind) {
            $net = ($asserts[$author] ?? 0) - ($retracts[$author] ?? 0);
            $hasRetract = ($retracts[$author] ?? 0) > 0;

            if ($kind === KnowledgeAuthorKind::Owner && $net > 0) {
                $ownerPositive = true;
                $anyonePositive = true;
            }

            if ($kind === KnowledgeAuthorKind::Agent && $net > 0) {
                $agentCount++;
                $repeats += min(self::REPEAT_CAP_PER_AGENT, max(0, $net - 1));
                $anyonePositive = true;
            }

            if ($hasRetract) {
                $disputeAuthors++;
            }
        }

        $raw = 0;
        if ($ownerPositive) {
            $raw += self::CONFIDENCE_OWNER_ASSERT;
        }
        $raw += self::CONFIDENCE_PER_AGENT * $agentCount;
        $raw += self::CONFIDENCE_PER_REPEAT * $repeats;

        $disputed = $anyonePositive && $disputeAuthors > 0;
        if ($disputed) {
            $raw -= self::CONFIDENCE_DISPUTE * $disputeAuthors;
        }

        $confidence = max(0, min(self::CONFIDENCE_MAX, $raw));
        if ($ownerConfirmed || $permanent) {
            $confidence = max($confidence, self::CONFIDENCE_OWNER_FLOOR);
        }

        $active = $anyonePositive || $ownerConfirmed || $permanent;
        $link->status = $active ? KnowledgeLinkStatus::Active : KnowledgeLinkStatus::Retracted;
        $link->confidence = $active ? $confidence : 0;
        $link->disputed = $active && $disputed;
        $link->save();

        return $link;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordObservation(
        KnowledgeLink $link,
        KnowledgeEntry $subject,
        KnowledgeObservationKind $kind,
        string $author,
        KnowledgeAuthorKind $authorKind,
        array $payload,
        ?string $source = null,
        ?Carbon $occurredAt = null,
    ): KnowledgeObservation {
        return $link->observations()->create([
            'kind' => $kind,
            'entry_id' => $subject->id,
            'author' => $author,
            'author_kind' => $authorKind,
            'payload' => $payload,
            'source' => $source,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function ownerStamp(KnowledgeLink $link, string $author, KnowledgeObservationKind $kind): KnowledgeLink
    {
        return DB::transaction(function () use ($link, $author, $kind): KnowledgeLink {
            $link->loadMissing(['fromEntry', 'toEntry']);

            $this->recordObservation(
                $link,
                $link->fromEntry,
                $kind,
                $author,
                KnowledgeAuthorKind::Owner,
                [
                    'from' => $link->fromEntry->name,
                    'to' => $link->toEntry->name,
                    'predicate' => $link->predicate->value,
                ],
            );

            return $this->recompute($link->fresh());
        });
    }

    private function resolveEndpoint(string $name, bool $createMissing, string $author): ?KnowledgeEntry
    {
        $entry = KnowledgeEntry::lookup($name);

        if ($entry !== null || ! $createMissing) {
            return $entry;
        }

        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }

        $entry = new KnowledgeEntry([
            'name' => $trimmed,
            'name_lower' => Str::lower($trimmed),
            'type' => 'concept',
            'aliases' => [],
            'summary' => '',
            'state' => '',
            'related' => [],
            'updated_by' => $author,
            'version' => 1,
        ]);
        $entry->save();
        $entry->recordVersion($author);

        return $entry;
    }

    private function attachNamedEvent(
        string $eventName,
        KnowledgeEntry $from,
        KnowledgeEntry $to,
        string $author,
        KnowledgeAuthorKind $authorKind,
        ?string $source,
        ?Carbon $occurredAt,
    ): void {
        $event = KnowledgeEntry::lookup($eventName);
        if ($event === null) {
            $event = new KnowledgeEntry([
                'name' => trim($eventName),
                'name_lower' => Str::lower(trim($eventName)),
                'type' => 'event',
                'aliases' => [],
                'summary' => '',
                'state' => '',
                'related' => [],
                'updated_by' => $author,
                'version' => 1,
            ]);
            $event->save();
            $event->recordVersion($author);
        }

        foreach ([$from, $to] as $participant) {
            if ($participant->is($event)) {
                continue;
            }
            $this->connect(
                author: $author,
                authorKind: $authorKind,
                from: $event->name,
                to: $participant->name,
                predicate: KnowledgePredicate::Involves,
                source: $source,
                occurredAt: $occurredAt,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeHop(KnowledgeLink $link, bool $outgoing): array
    {
        $peer = $outgoing ? $link->toEntry : $link->fromEntry;
        $predicate = $outgoing ? $link->predicate : $link->predicate->inverse();

        return [
            'id' => $link->id,
            'predicate' => $predicate->value,
            'stored_predicate' => $link->predicate->value,
            'name' => $peer->name,
            'type' => $peer->type,
            'summary' => $peer->summary,
            'confidence' => $link->confidence,
            'disputed' => $link->disputed,
            'permanent' => $link->permanent,
            'status' => $link->status->value,
            'observations' => $link->observation_count,
        ];
    }

    /**
     * @return array<int, true>
     */
    private function focusIds(int $focusId, int $hops, int $limit): array
    {
        $keep = [$focusId => true];
        $frontier = [$focusId];

        for ($depth = 0; $depth < $hops; $depth++) {
            $peers = KnowledgeLink::query()
                ->where('status', KnowledgeLinkStatus::Active)
                ->where(function ($q) use ($frontier) {
                    $q->whereIn('from_entry_id', $frontier)
                        ->orWhereIn('to_entry_id', $frontier);
                })
                ->get(['from_entry_id', 'to_entry_id']);

            $next = [];
            foreach ($peers as $link) {
                foreach ([$link->from_entry_id, $link->to_entry_id] as $id) {
                    if (! isset($keep[$id])) {
                        $keep[$id] = true;
                        $next[] = $id;
                    }
                    if (count($keep) >= $limit) {
                        return $keep;
                    }
                }
            }
            $frontier = $next;
            if ($frontier === []) {
                break;
            }
        }

        return $keep;
    }
}
