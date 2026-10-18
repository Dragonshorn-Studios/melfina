<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentAddressingPreference;
use App\Models\AgentEntityReference;
use App\Models\AgentEntityReferenceKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeEntryRedirect;
use App\Models\KnowledgeIdentifier;
use App\Models\KnowledgeIdentifierKind;
use App\Models\KnowledgeIdentityStatus;
use App\Models\User;
use App\Support\KnowledgeName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner-only identity bindings: operator, global identifiers, scoped
 * references, addressing preferences, and duplicate-id redirects.
 */
class KnowledgeIdentityAdmin
{
    public function __construct(private KnowledgeIdentityResolver $resolver) {}

    public function bindOperator(KnowledgeEntry $person, User $owner): void
    {
        if (! $owner->is_owner) {
            throw ValidationException::withMessages([
                'operator_entry_id' => 'Only the installation owner can bind the operator.',
            ]);
        }

        if ($person->type !== 'person') {
            throw ValidationException::withMessages([
                'operator_entry_id' => 'The operator must be a person in the knowledge graph.',
            ]);
        }

        if ($this->resolver->isRedirectSource($person->id)) {
            throw ValidationException::withMessages([
                'operator_entry_id' => 'Bind the canonical person, not a redirected duplicate.',
            ]);
        }

        $disagreement = User::query()
            ->where('is_owner', true)
            ->whereNotNull('operator_entry_id')
            ->where('operator_entry_id', '!=', $person->id)
            ->whereKeyNot($owner->id)
            ->exists();

        if ($disagreement) {
            throw ValidationException::withMessages([
                'operator_entry_id' => 'Another owner already bound a different operator person.',
            ]);
        }

        $owner->operator_entry_id = $person->id;
        $owner->save();
    }

    public function unbindOperator(User $owner): void
    {
        $owner->operator_entry_id = null;
        $owner->save();
    }

    public function addIdentifier(
        KnowledgeEntry $entry,
        string $value,
        KnowledgeIdentifierKind $kind,
        string $author = 'owner',
    ): KnowledgeIdentifier {
        $value = trim($value);
        $normalized = KnowledgeName::normalize($value);

        if ($value === '' || $normalized === '') {
            throw ValidationException::withMessages(['value' => 'Identifier is empty.']);
        }

        if (KnowledgeName::isOperatorToken($normalized)) {
            throw ValidationException::withMessages(['value' => 'operator is a reserved installation role, not a global alias.']);
        }

        if ($normalized === $entry->name_lower) {
            throw ValidationException::withMessages(['value' => 'That is already the canonical name.']);
        }

        $existing = KnowledgeIdentifier::query()
            ->where('entry_id', $entry->id)
            ->where('normalized_value', $normalized)
            ->first();

        if ($existing !== null) {
            if ($existing->kind === $kind) {
                return $existing;
            }

            throw ValidationException::withMessages([
                'value' => "Already stored as a {$existing->kind->value} of this entry.",
            ]);
        }

        $identifier = $entry->identifiers()->create([
            'value' => $value,
            'normalized_value' => $normalized,
            'kind' => $kind,
            'author' => $author,
        ]);

        if ($kind === KnowledgeIdentifierKind::Alias) {
            $entry->syncAliasProjection();
        }

        return $identifier;
    }

    public function removeIdentifier(KnowledgeIdentifier $identifier): void
    {
        $entry = $identifier->entry;
        $kind = $identifier->kind;
        $identifier->delete();

        if ($kind === KnowledgeIdentifierKind::Alias && $entry !== null) {
            $entry->syncAliasProjection();
        }
    }

    /**
     * Bind a contextual title for one or more agents. Rejects a registration
     * that would conceal a different unambiguous global person under the
     * same spelling.
     *
     * @param  list<Agent>  $agents
     * @return list<AgentEntityReference>
     */
    public function bindReference(
        array $agents,
        KnowledgeEntry $entry,
        string $value,
        AgentEntityReferenceKind $kind = AgentEntityReferenceKind::AddressTerm,
        string $author = 'owner',
    ): array {
        $value = trim($value);
        $normalized = KnowledgeName::normalize($value);

        if ($value === '' || $normalized === '') {
            throw ValidationException::withMessages(['value' => 'Reference is empty.']);
        }

        if (KnowledgeName::isOperatorToken($normalized)) {
            throw ValidationException::withMessages(['value' => 'Use the operator binding, not a scoped title named operator.']);
        }

        $global = $this->resolver->resolve(KnowledgeIdentityContext::global(), query: $value);

        if ($global->found() && $global->entry->id !== $entry->id) {
            throw ValidationException::withMessages([
                'value' => "'{$value}' already identifies {$global->entry->name} globally. Scoped titles cannot conceal that person.",
            ]);
        }

        if ($global->status === KnowledgeIdentityStatus::Ambiguous) {
            throw ValidationException::withMessages([
                'value' => "'{$value}' is already ambiguous globally. Resolve that before binding a scoped title.",
            ]);
        }

        $created = [];

        foreach ($agents as $agent) {
            $row = AgentEntityReference::query()->firstOrNew([
                'agent_id' => $agent->id,
                'normalized_value' => $normalized,
            ]);

            if ($row->exists && $row->entry_id !== $entry->id) {
                throw ValidationException::withMessages([
                    'value' => "{$agent->name} already uses '{$value}' for another entity.",
                ]);
            }

            $row->entry_id = $entry->id;
            $row->value = $value;
            $row->kind = $kind;
            $row->author = $author;
            $row->save();
            $created[] = $row;
        }

        return $created;
    }

    public function unbindReference(Agent $agent, string $value): void
    {
        AgentEntityReference::query()
            ->where('agent_id', $agent->id)
            ->where('normalized_value', KnowledgeName::normalize($value))
            ->delete();
    }

    public function setAddressingPreference(
        Agent $agent,
        KnowledgeEntry $person,
        string $form,
        string $context = 'default',
        string $author = 'owner',
    ): AgentAddressingPreference {
        if ($person->type !== 'person') {
            throw ValidationException::withMessages(['person_entry_id' => 'Addressing preferences apply to people.']);
        }

        $preference = AgentAddressingPreference::query()->firstOrNew([
            'agent_id' => $agent->id,
            'person_entry_id' => $person->id,
            'context' => $context,
        ]);
        $preference->form = trim($form);
        $preference->author = $author;
        $preference->save();

        return $preference;
    }

    /**
     * Copy JSON aliases into knowledge_identifiers. Idempotent. Reports
     * names that already identify more than one entry.
     *
     * @return array{created: int, skipped: int, collisions: list<array{normalized: string, entry_ids: list<int>}>}
     */
    public function backfillAliases(): array
    {
        $created = 0;
        $skipped = 0;

        KnowledgeEntry::query()->orderBy('id')->each(function (KnowledgeEntry $entry) use (&$created, &$skipped): void {
            foreach ($entry->aliases ?? [] as $alias) {
                if (! is_string($alias)) {
                    continue;
                }

                $value = trim($alias);
                $normalized = KnowledgeName::normalize($value);

                if ($value === '' || $normalized === '' || $normalized === $entry->name_lower) {
                    $skipped++;

                    continue;
                }

                $exists = $entry->identifiers()->where('normalized_value', $normalized)->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $entry->identifiers()->create([
                    'value' => $value,
                    'normalized_value' => $normalized,
                    'kind' => KnowledgeIdentifierKind::Alias,
                    'author' => $entry->updated_by !== '' ? $entry->updated_by : 'owner',
                ]);
                $created++;
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'collisions' => $this->identifierCollisions(),
        ];
    }

    /**
     * @return list<array{normalized: string, entry_ids: list<int>}>
     */
    public function identifierCollisions(): array
    {
        $canonical = KnowledgeEntry::query()->get(['id', 'name_lower']);
        $rows = KnowledgeIdentifier::query()->get(['entry_id', 'normalized_value']);

        $index = [];
        foreach ($canonical as $entry) {
            $index[$entry->name_lower][$entry->id] = true;
        }
        foreach ($rows as $row) {
            $index[$row->normalized_value][$row->entry_id] = true;
        }

        $collisions = [];
        foreach ($index as $normalized => $ids) {
            if (count($ids) > 1) {
                $collisions[] = [
                    'normalized' => $normalized,
                    'entry_ids' => array_map('intval', array_keys($ids)),
                ];
            }
        }

        return $collisions;
    }

    /**
     * Dry-run by default. Creates redirects from source ids to the
     * canonical person. Does not delete history, does not rewrite
     * observation payloads, and does not copy scoped titles into aliases.
     *
     * @param  array{canonical_id: int, source_ids: list<int>, keep_summary_from?: int, keep_state_from?: int, rebind_operator?: bool}  $manifest
     * @return array<string, mixed>
     */
    public function repairDuplicates(array $manifest, bool $dryRun = true): array
    {
        $canonicalId = (int) ($manifest['canonical_id'] ?? 0);
        $sourceIds = array_values(array_unique(array_map('intval', $manifest['source_ids'] ?? [])));
        $canonical = KnowledgeEntry::query()->find($canonicalId);

        if ($canonical === null) {
            throw ValidationException::withMessages(['canonical_id' => 'Canonical entry does not exist.']);
        }

        if (in_array($canonicalId, $sourceIds, true)) {
            throw ValidationException::withMessages(['source_ids' => 'Canonical id cannot redirect to itself.']);
        }

        $report = [
            'dry_run' => $dryRun,
            'canonical_id' => $canonical->id,
            'canonical_name' => $canonical->name,
            'redirects' => [],
            'skipped' => [],
            'wiki' => [],
            'identifier_copies' => [],
            'identifier_collisions' => [],
            'self_links' => [],
            'edge_collisions' => [],
            'operator' => null,
        ];

        $sources = KnowledgeEntry::query()->whereIn('id', $sourceIds)->with('identifiers')->get()->keyBy('id');
        $queuedIdentifiers = [];

        foreach ($sourceIds as $sourceId) {
            if (! $sources->has($sourceId)) {
                $report['skipped'][] = ['id' => $sourceId, 'reason' => 'missing'];

                continue;
            }

            $cycle = $this->wouldCycle($sourceId, $canonical->id);
            if ($cycle) {
                $report['skipped'][] = ['id' => $sourceId, 'reason' => 'cycle'];

                continue;
            }

            $report['redirects'][] = [
                'from' => $sourceId,
                'from_name' => $sources[$sourceId]->name,
                'to' => $canonical->id,
            ];
        }

        $summaryFrom = (int) ($manifest['keep_summary_from'] ?? $canonical->id);
        $stateFrom = (int) ($manifest['keep_state_from'] ?? $canonical->id);
        $summaryEntry = KnowledgeEntry::query()->find($summaryFrom);
        $stateEntry = KnowledgeEntry::query()->find($stateFrom);

        if ($summaryEntry !== null && $summaryEntry->summary !== $canonical->summary) {
            $report['wiki'][] = [
                'field' => 'summary',
                'keep_from' => $summaryFrom,
                'canonical' => $canonical->summary,
                'other' => $summaryEntry->summary,
            ];
        }
        if ($stateEntry !== null && $stateEntry->state !== $canonical->state) {
            $report['wiki'][] = [
                'field' => 'state',
                'keep_from' => $stateFrom,
                'canonical' => $canonical->state,
                'other' => $stateEntry->state,
            ];
        }

        foreach ($sources as $source) {
            foreach ($source->identifiers as $identifier) {
                $normalized = $identifier->normalized_value;
                $exists = $canonical->identifiers()
                    ->where('normalized_value', $normalized)
                    ->exists();
                if ($exists || $normalized === $canonical->name_lower || isset($queuedIdentifiers[$normalized])) {
                    $report['identifier_collisions'][] = [
                        'value' => $identifier->value,
                        'from' => $source->id,
                    ];

                    continue;
                }
                $queuedIdentifiers[$normalized] = true;
                $report['identifier_copies'][] = [
                    'value' => $identifier->value,
                    'kind' => $identifier->kind->value,
                    'from' => $source->id,
                ];
            }

            if ($source->name_lower !== $canonical->name_lower) {
                // Source canonical name stays on the closed row so lookup
                // follows the redirect. It is not copied as a global alias.
            }
        }

        $operatorOwners = User::query()->whereIn('operator_entry_id', $sourceIds)->get();
        if ($operatorOwners->isNotEmpty()) {
            $report['operator'] = [
                'current_ids' => $operatorOwners->pluck('operator_entry_id')->unique()->values()->all(),
                'rebind_to' => $canonical->id,
                'will_rebind' => (bool) ($manifest['rebind_operator'] ?? false),
            ];
        }

        if ($dryRun) {
            return $report;
        }

        DB::transaction(function () use ($report, $canonical, $summaryEntry, $stateEntry, $manifest, $operatorOwners): void {
            foreach ($report['redirects'] as $redirect) {
                KnowledgeEntryRedirect::query()->updateOrCreate(
                    ['from_entry_id' => $redirect['from']],
                    ['to_entry_id' => $redirect['to'], 'author' => 'owner'],
                );
            }

            foreach ($report['identifier_copies'] as $copy) {
                $canonical->identifiers()->create([
                    'value' => $copy['value'],
                    'normalized_value' => KnowledgeName::normalize($copy['value']),
                    'kind' => KnowledgeIdentifierKind::from($copy['kind']),
                    'author' => 'owner',
                ]);
            }

            if ($summaryEntry !== null && (int) ($manifest['keep_summary_from'] ?? $canonical->id) !== $canonical->id) {
                $canonical->summary = $summaryEntry->summary;
            }
            if ($stateEntry !== null && (int) ($manifest['keep_state_from'] ?? $canonical->id) !== $canonical->id) {
                $canonical->state = $stateEntry->state;
            }
            $canonical->save();

            if (($manifest['rebind_operator'] ?? false) === true) {
                foreach ($operatorOwners as $owner) {
                    $owner->operator_entry_id = $canonical->id;
                    $owner->save();
                }
            }
        });

        $report['applied'] = true;

        return $report;
    }

    private function wouldCycle(int $fromId, int $toId): bool
    {
        if ($fromId === $toId) {
            return true;
        }

        $seen = [$fromId => true];
        $current = $toId;

        for ($i = 0; $i < 32; $i++) {
            if (isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $next = KnowledgeEntryRedirect::query()->where('from_entry_id', $current)->value('to_entry_id');
            if ($next === null) {
                return false;
            }
            $current = (int) $next;
        }

        return true;
    }
}
