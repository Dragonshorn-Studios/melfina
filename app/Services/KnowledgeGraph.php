<?php

namespace App\Services;

use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeEntryRedirect;
use App\Models\KnowledgeIdentityStatus;
use App\Models\KnowledgeLink;
use App\Models\KnowledgeLinkStatus;
use App\Models\KnowledgeObservation;
use App\Models\KnowledgeObservationKind;
use App\Models\KnowledgePredicate;
use App\Support\KnowledgeName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    public function __construct(private KnowledgeIdentityResolver $resolver) {}

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
        ?KnowledgeIdentityContext $identity = null,
        ?int $fromId = null,
        ?int $toId = null,
    ): KnowledgeConnectResult {
        if ($op !== KnowledgeObservationKind::LinkAssert && $op !== KnowledgeObservationKind::LinkRetract) {
            return new KnowledgeConnectResult(ok: false, error: 'op must be link_assert or link_retract.');
        }

        $identity ??= KnowledgeIdentityContext::global();

        try {
            return DB::transaction(function () use (
                $author,
                $authorKind,
                $from,
                $to,
                $predicate,
                $op,
                $note,
                $data,
                $source,
                $occurredAt,
                $createMissing,
                $eventName,
                $identity,
                $fromId,
                $toId,
            ): KnowledgeConnectResult {
                $fromEntry = $this->requireEndpoint($from, $fromId, $createMissing, $author, $identity, 'from');
                $toEntry = $this->requireEndpoint($to, $toId, $createMissing, $author, $identity, 'to');

                if ($fromEntry->is($toEntry)) {
                    throw new KnowledgeConnectAborted(new KnowledgeConnectResult(ok: false, error: 'A link cannot point an entity at itself.'));
                }

                if ($predicate->swapsEndpoints()) {
                    [$fromEntry, $toEntry] = [$toEntry, $fromEntry];
                }
                $stored = $predicate->stored();

                $link = KnowledgeLink::query()->firstOrNew([
                    'from_entry_id' => $fromEntry->id,
                    'to_entry_id' => $toEntry->id,
                    'predicate' => $stored,
                ]);

                $created = ! $link->exists;

                if ($op === KnowledgeObservationKind::LinkRetract && $created) {
                    throw new KnowledgeConnectAborted(new KnowledgeConnectResult(
                        ok: false,
                        error: 'No such link to retract.',
                    ));
                }

                if ($op === KnowledgeObservationKind::LinkRetract
                    && $link->permanent
                    && $authorKind === KnowledgeAuthorKind::Agent) {
                    throw new KnowledgeConnectAborted(new KnowledgeConnectResult(
                        ok: false,
                        link: $link,
                        error: 'This link is permanent — only the owner can retract it.',
                    ));
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
                    $eventResult = $this->attachNamedEvent($eventName, $fromEntry, $toEntry, $author, $authorKind, $source, $occurredAt, $identity);
                    if (! $eventResult->ok) {
                        throw new KnowledgeConnectAborted($eventResult);
                    }
                }

                return new KnowledgeConnectResult(
                    ok: true,
                    link: $link->fresh(['fromEntry', 'toEntry']),
                    observation: $observation,
                    created: $created,
                );
            });
        } catch (KnowledgeConnectAborted $e) {
            return $e->result;
        }
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
        $ids = $this->equivalentIds($entry);
        $canonical = $this->resolver->canonicalEntry($entry) ?? $entry;

        $outgoing = KnowledgeLink::query()
            ->with(['toEntry', 'fromEntry'])
            ->whereIn('from_entry_id', $ids)
            ->when(! $includeRetracted, fn ($q) => $q->where('status', KnowledgeLinkStatus::Active))
            ->where('confidence', '>=', $minConfidence)
            ->get();

        $incoming = KnowledgeLink::query()
            ->with(['toEntry', 'fromEntry'])
            ->whereIn('to_entry_id', $ids)
            ->when(! $includeRetracted, fn ($q) => $q->where('status', KnowledgeLinkStatus::Active))
            ->where('confidence', '>=', $minConfidence)
            ->get();

        return [
            'outgoing' => $this->dedupeHops($outgoing, outgoing: true, selfId: $canonical->id, minConfidence: $minConfidence),
            'incoming' => $this->dedupeHops($incoming, outgoing: false, selfId: $canonical->id, minConfidence: $minConfidence),
        ];
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
        $canonical = $this->resolver->canonicalEntry($entry) ?? $entry;
        $seen = [$canonical->id => true];
        $frontier = $this->equivalentIds($canonical);
        $edges = [];
        $seenEdges = [];

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

                $from = $this->resolver->canonicalEntry($link->fromEntry) ?? $link->fromEntry;
                $to = $this->resolver->canonicalEntry($link->toEntry) ?? $link->toEntry;
                $peerId = in_array($fromId, $frontier, true) ? $to->id : $from->id;

                $edgeKey = $from->id.'|'.$to->id.'|'.$link->predicate->value.'|'.$depth;
                if (isset($seenEdges[$edgeKey]) || $from->id === $to->id) {
                    continue;
                }
                $seenEdges[$edgeKey] = true;

                $edges[] = [
                    'from' => $from->name,
                    'from_id' => $from->id,
                    'to' => $to->name,
                    'to_id' => $to->id,
                    'predicate' => $link->predicate->value,
                    'confidence' => $link->confidence,
                    'permanent' => $link->permanent,
                    'hops' => $depth,
                ];

                if (! isset($seen[$peerId])) {
                    $seen[$peerId] = true;
                    $next = array_merge($next, $this->equivalentIdsById($peerId));
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
        $redirectSources = KnowledgeEntryRedirect::query()->pluck('from_entry_id')->all();
        $entries = KnowledgeEntry::query()
            ->whereNotIn('id', $redirectSources ?: [0])
            ->orderBy('name_lower')
            ->get();
        $links = KnowledgeLink::query()
            ->where('status', KnowledgeLinkStatus::Active)
            ->get();

        if ($focusId !== null && $entries->count() > $limit) {
            $keep = $this->focusIds($this->resolver->canonicalId($focusId) ?? $focusId, $hops, $limit);
            // focusIds() returns a set keyed by entry id; the filter has to
            // compare against the ids, not against the keys' values.
            $entries = $entries->whereIn('id', array_keys($keep))->values();
        } elseif ($entries->count() > $limit) {
            $entries = $entries->take($limit)->values();
        }

        $visible = $entries->pluck('id')->flip()->all();
        $deduped = [];
        foreach ($links as $link) {
            $fromId = $this->resolver->canonicalId($link->from_entry_id) ?? $link->from_entry_id;
            $toId = $this->resolver->canonicalId($link->to_entry_id) ?? $link->to_entry_id;
            if ($fromId === $toId || ! isset($visible[$fromId]) || ! isset($visible[$toId])) {
                continue;
            }
            $key = $fromId.'|'.$toId.'|'.$link->predicate->value;
            if (! isset($deduped[$key]) || $deduped[$key]['confidence'] < $link->confidence) {
                $deduped[$key] = [
                    'id' => $link->id,
                    'from' => $fromId,
                    'to' => $toId,
                    'predicate' => $link->predicate->value,
                    'confidence' => $link->confidence,
                    'permanent' => $link->permanent,
                    'disputed' => $link->disputed,
                ];
            }
        }

        return [
            'nodes' => $entries->map(fn (KnowledgeEntry $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'type' => $e->type,
                'summary' => $e->summary,
            ])->values()->all(),
            'links' => array_values($deduped),
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

            // A link_fix corrects the endpoints of a link that already
            // exists; it is not an assertion that the link holds, so it
            // counts neither towards the owner's confidence nor towards
            // reviving a link the owner retracted (docs/knowledge-graph.md).
            if (in_array($obs->kind, [
                KnowledgeObservationKind::LinkAssert,
                KnowledgeObservationKind::OwnerConfirm,
                KnowledgeObservationKind::OwnerPermanent,
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

    private function requireEndpoint(
        string $name,
        ?int $id,
        bool $createMissing,
        string $author,
        KnowledgeIdentityContext $identity,
        string $end,
        string $createType = 'concept',
    ): KnowledgeEntry {
        $resolved = $this->resolver->resolve($identity, query: $name, entryId: $id);

        if ($resolved->status === KnowledgeIdentityStatus::Ambiguous) {
            throw new KnowledgeConnectAborted(new KnowledgeConnectResult(
                ok: false,
                error: $resolved->error,
                code: 'ambiguous_reference',
                ambiguous: [$end => $resolved->candidates],
            ));
        }

        if (in_array($resolved->status, [
            KnowledgeIdentityStatus::Inconsistent,
            KnowledgeIdentityStatus::Conflict,
            KnowledgeIdentityStatus::Closed,
        ], true)) {
            throw new KnowledgeConnectAborted(new KnowledgeConnectResult(
                ok: false,
                error: $resolved->error,
                code: $resolved->code,
            ));
        }

        if ($resolved->found()) {
            if ($createType === 'event' && $resolved->entry->type !== 'event') {
                throw new KnowledgeConnectAborted(new KnowledgeConnectResult(
                    ok: false,
                    error: "'{$resolved->entry->name}' is a {$resolved->entry->type}, not an event.",
                    code: 'wrong_entity_type',
                ));
            }

            return $resolved->entry;
        }

        if (! $createMissing) {
            throw new KnowledgeConnectAborted(new KnowledgeConnectResult(
                ok: false,
                unresolved: [$end => $name],
            ));
        }

        $trimmed = trim($name);
        if ($trimmed === '' || KnowledgeName::isOperatorToken(KnowledgeName::normalize($trimmed))) {
            throw new KnowledgeConnectAborted(new KnowledgeConnectResult(
                ok: false,
                unresolved: [$end => $name],
            ));
        }

        return $this->createStub($trimmed, $author, $createType);
    }

    private function createStub(string $name, string $author, string $type): KnowledgeEntry
    {
        $entry = new KnowledgeEntry([
            'name' => $name,
            'name_lower' => KnowledgeName::normalize($name),
            'type' => $type,
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
        KnowledgeIdentityContext $identity,
    ): KnowledgeConnectResult {
        $resolved = $this->resolver->resolve($identity, query: $eventName);

        if ($resolved->status === KnowledgeIdentityStatus::Ambiguous) {
            return new KnowledgeConnectResult(
                ok: false,
                error: $resolved->error,
                code: 'ambiguous_reference',
                ambiguous: ['event' => $resolved->candidates],
            );
        }

        if (in_array($resolved->status, [
            KnowledgeIdentityStatus::Inconsistent,
            KnowledgeIdentityStatus::Conflict,
        ], true)) {
            return new KnowledgeConnectResult(ok: false, error: $resolved->error, code: $resolved->code);
        }

        if ($resolved->found()) {
            if ($resolved->entry->type !== 'event') {
                return new KnowledgeConnectResult(
                    ok: false,
                    error: "'{$resolved->entry->name}' is a {$resolved->entry->type}, not an event.",
                    code: 'wrong_entity_type',
                );
            }
            $event = $resolved->entry;
        } else {
            $event = $this->createStub(trim($eventName), $author, 'event');
        }

        foreach ([$from, $to] as $participant) {
            if ($participant->is($event)) {
                continue;
            }

            $result = $this->connect(
                author: $author,
                authorKind: $authorKind,
                from: $event->name,
                to: $participant->name,
                predicate: KnowledgePredicate::Involves,
                source: $source,
                occurredAt: $occurredAt,
                identity: $identity,
                fromId: $event->id,
                toId: $participant->id,
            );

            if (! $result->ok) {
                return $result;
            }
        }

        return new KnowledgeConnectResult(ok: true);
    }

    /**
     * @param  Collection<int, KnowledgeLink>  $links
     * @return list<array<string, mixed>>
     */
    private function dedupeHops($links, bool $outgoing, int $selfId, int $minConfidence): array
    {
        $grouped = [];

        foreach ($links as $link) {
            $hop = $this->serializeHop($link, $outgoing);
            if ($hop === null || $hop['entry_id'] === $selfId) {
                continue;
            }
            if ($hop['confidence'] < $minConfidence) {
                continue;
            }
            $key = $hop['entry_id'].'|'.$hop['predicate'];
            $grouped[$key][] = ['hop' => $hop, 'link_id' => $link->id];
        }

        $out = [];
        foreach ($grouped as $rows) {
            $linkIds = array_column($rows, 'link_id');
            $best = $rows[0]['hop'];
            foreach ($rows as $row) {
                if ($row['hop']['confidence'] > $best['confidence']) {
                    $best = $row['hop'];
                }
            }
            $best['observations'] = KnowledgeObservation::query()
                ->whereIn('link_id', $linkIds)
                ->count();
            $out[] = $best;
        }

        return array_values($out);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeHop(KnowledgeLink $link, bool $outgoing): ?array
    {
        $peer = $outgoing ? $link->toEntry : $link->fromEntry;
        if ($peer === null) {
            return null;
        }

        $canonical = $this->resolver->canonicalEntry($peer) ?? $peer;
        $predicate = $outgoing ? $link->predicate : $link->predicate->inverse();

        return [
            'id' => $link->id,
            'predicate' => $predicate->value,
            'stored_predicate' => $link->predicate->value,
            'entry_id' => $canonical->id,
            'name' => $canonical->name,
            'type' => $canonical->type,
            'summary' => $canonical->summary,
            'confidence' => $link->confidence,
            'disputed' => $link->disputed,
            'permanent' => $link->permanent,
            'status' => $link->status->value,
            'observations' => $link->observation_count,
        ];
    }

    /**
     * Canonical id plus every historical id that redirects here.
     *
     * @return list<int>
     */
    private function equivalentIds(KnowledgeEntry $entry): array
    {
        $canonical = $this->resolver->canonicalEntry($entry) ?? $entry;

        return $this->equivalentIdsById($canonical->id);
    }

    /**
     * @return list<int>
     */
    private function equivalentIdsById(int $canonicalId): array
    {
        $ids = [$canonicalId];
        $redirects = KnowledgeEntryRedirect::query()->get(['from_entry_id', 'to_entry_id']);
        $changed = true;

        while ($changed) {
            $changed = false;
            foreach ($redirects as $redirect) {
                if (in_array($redirect->to_entry_id, $ids, true) && ! in_array($redirect->from_entry_id, $ids, true)) {
                    $ids[] = $redirect->from_entry_id;
                    $changed = true;
                }
            }
        }

        return $ids;
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
