<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeIdentityStatus;
use App\Models\KnowledgePersonaLink;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Persona links connect person entries that are the same living being.
 * Lookup keeps each entry's own name and reports the equality.
 */
class PersonaLinks
{
    public function __construct(private KnowledgeIdentityResolver $resolver) {}

    public function link(KnowledgeEntry $a, KnowledgeEntry $b): KnowledgePersonaLink
    {
        $this->assertPerson($a, 'persona');
        $this->assertPerson($b, 'persona');

        if ($a->id === $b->id) {
            throw ValidationException::withMessages([
                'persona' => 'A person cannot be their own persona.',
            ]);
        }

        [$low, $high] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        return KnowledgePersonaLink::query()->firstOrCreate([
            'entry_low_id' => $low,
            'entry_high_id' => $high,
        ]);
    }

    public function unlink(KnowledgeEntry $a, KnowledgeEntry $b): void
    {
        if ($a->id === $b->id) {
            return;
        }

        [$low, $high] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        KnowledgePersonaLink::query()
            ->where('entry_low_id', $low)
            ->where('entry_high_id', $high)
            ->delete();
    }

    /**
     * @return Collection<int, KnowledgeEntry>
     */
    public function component(KnowledgeEntry $entry): Collection
    {
        $seen = [$entry->id => true];
        $queue = [$entry->id];

        while ($queue !== []) {
            $id = array_shift($queue);
            $neighbors = KnowledgePersonaLink::query()
                ->where('entry_low_id', $id)
                ->orWhere('entry_high_id', $id)
                ->get(['entry_low_id', 'entry_high_id']);

            foreach ($neighbors as $link) {
                foreach ([$link->entry_low_id, $link->entry_high_id] as $next) {
                    if (! isset($seen[$next])) {
                        $seen[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
        }

        return KnowledgeEntry::query()
            ->whereIn('id', array_keys($seen))
            ->orderBy('name_lower')
            ->get();
    }

    public function sameBeing(KnowledgeEntry $a, KnowledgeEntry $b): bool
    {
        if ($a->id === $b->id) {
            return true;
        }

        return $this->component($a)->contains('id', $b->id);
    }

    /**
     * The person this agent handles: its own choice, else a single group
     * choice, else the installation operator.
     */
    public function mainPerson(Agent $agent): ?KnowledgeEntry
    {
        $agent->loadMissing('mainPerson', 'groups.mainPerson');

        if ($agent->mainPerson !== null && $agent->mainPerson->type === 'person') {
            return $agent->mainPerson;
        }

        $fromGroups = $agent->groups
            ->map(fn (AgentGroup $group) => $group->mainPerson)
            ->filter(fn (?KnowledgeEntry $person) => $person !== null && $person->type === 'person')
            ->unique('id')
            ->values();

        if ($fromGroups->count() === 1) {
            return $fromGroups->first();
        }

        $binding = $this->resolver->operatorBinding();

        return $binding['status'] === KnowledgeIdentityStatus::Resolved ? $binding['entry'] : null;
    }

    public function operatorPerson(): ?KnowledgeEntry
    {
        $binding = $this->resolver->operatorBinding();

        return $binding['status'] === KnowledgeIdentityStatus::Resolved ? $binding['entry'] : null;
    }

    /**
     * Paragraph appended to read_instructions. The agent's main person
     * wins; the operator person's copy and the legacy user column follow.
     */
    public function instructionCopy(Agent $agent): string
    {
        $main = $this->mainPerson($agent);
        $copy = trim((string) ($main?->instruction_copy ?? ''));

        if ($copy !== '') {
            return $copy;
        }

        $operator = $this->operatorPerson();
        if ($operator !== null && $operator->id !== $main?->id) {
            $operatorCopy = trim((string) ($operator->instruction_copy ?? ''));
            if ($operatorCopy !== '') {
                return $operatorCopy;
            }
        }

        return (string) (User::operatorPersona() ?? '');
    }

    /**
     * @return array{operator_entry_id: int|null, operator_name: string|null, main_person_entry_id: int|null, main_person_name: string|null, same_being: bool}
     */
    public function identityContext(Agent $agent): array
    {
        $operator = $this->operatorPerson();
        $main = $this->mainPerson($agent);

        return [
            'operator_entry_id' => $operator?->id,
            'operator_name' => $operator?->name,
            'main_person_entry_id' => $main?->id,
            'main_person_name' => $main?->name,
            'same_being' => $operator !== null && $main !== null && $this->sameBeing($operator, $main),
        ];
    }

    /**
     * @return array{entries: list<array{entry_id: int, name: string}>, operator_entry_id: int|null, operator_name: string|null, same_as_operator: bool, main_person_entry_id: int|null, main_person_name: string|null, is_main_person: bool}|null
     */
    public function beingPayload(KnowledgeEntry $entry, ?Agent $agent): ?array
    {
        if ($entry->type !== 'person') {
            return null;
        }

        $operator = $this->operatorPerson();
        $main = $agent !== null ? $this->mainPerson($agent) : null;
        $members = $this->component($entry);

        return [
            'entries' => $members->map(fn (KnowledgeEntry $person) => [
                'entry_id' => $person->id,
                'name' => $person->name,
            ])->values()->all(),
            'operator_entry_id' => $operator?->id,
            'operator_name' => $operator?->name,
            'same_as_operator' => $operator !== null && $this->sameBeing($entry, $operator),
            'main_person_entry_id' => $main?->id,
            'main_person_name' => $main?->name,
            'is_main_person' => $main !== null && $main->id === $entry->id,
        ];
    }

    /**
     * @return list<string>
     */
    public function preambles(Agent $agent): array
    {
        $agent->loadMissing('groups');

        return $agent->groups
            ->sortBy('name')
            ->map(fn (AgentGroup $group) => trim((string) ($group->preamble ?? '')))
            ->filter()
            ->values()
            ->all();
    }

    private function assertPerson(KnowledgeEntry $entry, string $field): void
    {
        if ($entry->type !== 'person') {
            throw ValidationException::withMessages([
                $field => 'Persona links join person entries.',
            ]);
        }
    }
}
