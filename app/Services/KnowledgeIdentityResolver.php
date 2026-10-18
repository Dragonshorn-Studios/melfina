<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentEntityReference;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeEntryRedirect;
use App\Models\KnowledgeIdentifier;
use App\Models\KnowledgeIdentityStatus;
use App\Models\User;
use App\Support\KnowledgeName;
use Illuminate\Support\Collection;

/**
 * Deterministic person/entity resolution for every knowledge path.
 * Agent-facing tools pass explicit context; {@see KnowledgeEntry::lookup()}
 * stays a global compatibility wrapper and never reads request state.
 */
class KnowledgeIdentityResolver
{
    public const MATCH_ENTRY_ID = 'entry_id';

    public const MATCH_OPERATOR = 'operator';

    public const MATCH_AGENT_REFERENCE = 'agent_reference';

    public const MATCH_AUTHOR_REFERENCE = 'author_reference';

    public const MATCH_CANONICAL = 'canonical_name';

    public const MATCH_IDENTIFIER = 'global_identifier';

    public const MATCH_LEGACY_ALIAS = 'legacy_alias';

    public const MATCH_REDIRECT = 'redirect';

    public const SCOPE_ID = 'id';

    public const SCOPE_INSTALLATION = 'installation';

    public const SCOPE_AGENT = 'agent';

    public const SCOPE_AUTHOR = 'author';

    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_REDIRECT = 'redirect';

    /**
     * Resolve a name, optional stable id, and optional structured ref.
     * When id and name both resolve, they must name the same canonical entry.
     */
    public function resolve(
        KnowledgeIdentityContext $context,
        ?string $query = null,
        ?int $entryId = null,
        ?string $ref = null,
    ): KnowledgeIdentityResult {
        $query = $query !== null ? trim($query) : null;
        $input = $query ?? ($ref !== null ? 'ref:'.$ref : ($entryId !== null ? (string) $entryId : ''));

        if (($query === null || $query === '') && $entryId === null && ($ref === null || $ref === '')) {
            return KnowledgeIdentityResult::notFound('');
        }

        if ($ref !== null && $ref !== '' && $ref !== KnowledgeName::OPERATOR_TOKEN) {
            return KnowledgeIdentityResult::conflict($input, 'Unknown structured ref. Use ref=operator for the installation operator.');
        }

        $idResult = $entryId !== null ? $this->resolveId($entryId, $input) : null;

        if ($idResult !== null && ! $idResult->found() && $idResult->status !== KnowledgeIdentityStatus::NotFound) {
            return $idResult;
        }

        $nameResult = null;
        $wantsOperator = $ref === KnowledgeName::OPERATOR_TOKEN
            || ($query !== null && KnowledgeName::isOperatorToken(KnowledgeName::normalize($query)));

        if ($query !== null && $query !== '' || $wantsOperator) {
            $nameResult = $this->resolveName($context, $query ?? KnowledgeName::OPERATOR_TOKEN, $wantsOperator);
        }

        if ($idResult?->found() && $nameResult === null) {
            return $idResult;
        }

        if ($idResult?->found() && $nameResult !== null) {
            if (! $nameResult->found()) {
                if ($nameResult->status === KnowledgeIdentityStatus::NotFound) {
                    return KnowledgeIdentityResult::conflict(
                        $input,
                        "entry_id {$entryId} does not match query '{$query}'.",
                    );
                }

                return $nameResult;
            }

            if ($idResult->entry->id !== $nameResult->entry->id) {
                return KnowledgeIdentityResult::conflict(
                    $input,
                    "entry_id {$entryId} ({$idResult->entry->name}) does not match query '{$query}' ({$nameResult->entry->name}).",
                );
            }

            return $idResult;
        }

        if ($nameResult !== null) {
            return $nameResult;
        }

        return $idResult ?? KnowledgeIdentityResult::notFound($input);
    }

    public function resolveId(int $entryId, string $input = ''): KnowledgeIdentityResult
    {
        $entry = KnowledgeEntry::query()->find($entryId);

        if ($entry === null) {
            return KnowledgeIdentityResult::notFound($input !== '' ? $input : (string) $entryId);
        }

        $canonical = $this->followRedirects($entry);

        if ($canonical === null) {
            return KnowledgeIdentityResult::inconsistent(
                $input !== '' ? $input : (string) $entryId,
                'Redirect cycle detected for entry '.$entryId.'.',
            );
        }

        $matchedBy = $canonical->id === $entry->id ? self::MATCH_ENTRY_ID : self::MATCH_REDIRECT;
        $scope = $canonical->id === $entry->id ? self::SCOPE_ID : self::SCOPE_REDIRECT;

        return KnowledgeIdentityResult::resolved($canonical, $matchedBy, $scope, $input !== '' ? $input : (string) $entryId);
    }

    /**
     * Canonical entry for a stored id, or null on a redirect cycle.
     */
    public function canonicalEntry(KnowledgeEntry $entry): ?KnowledgeEntry
    {
        return $this->followRedirects($entry);
    }

    public function canonicalId(int $entryId): ?int
    {
        $entry = KnowledgeEntry::query()->find($entryId);

        return $entry === null ? null : $this->followRedirects($entry)?->id;
    }

    public function isRedirectSource(int $entryId): bool
    {
        return KnowledgeEntryRedirect::query()->where('from_entry_id', $entryId)->exists();
    }

    /**
     * Installation operator person, or an inconsistent/missing result.
     * Never picks the first owner when bindings disagree.
     *
     * @return array{status: KnowledgeIdentityStatus, entry: ?KnowledgeEntry, error: ?string}
     */
    public function operatorBinding(): array
    {
        $owners = User::query()->where('is_owner', true)->get(['id', 'operator_entry_id']);

        if ($owners->isEmpty()) {
            return [
                'status' => KnowledgeIdentityStatus::NotFound,
                'entry' => null,
                'error' => 'No installation owner is configured.',
            ];
        }

        $boundIds = $owners->pluck('operator_entry_id')->filter()->unique()->values();

        if ($boundIds->count() > 1) {
            return [
                'status' => KnowledgeIdentityStatus::Inconsistent,
                'entry' => null,
                'error' => 'Owners disagree on the operator person binding.',
            ];
        }

        if ($boundIds->isEmpty()) {
            return [
                'status' => KnowledgeIdentityStatus::NotFound,
                'entry' => null,
                'error' => 'No operator person is bound.',
            ];
        }

        $entry = KnowledgeEntry::query()->find($boundIds->first());

        if ($entry === null) {
            return [
                'status' => KnowledgeIdentityStatus::Inconsistent,
                'entry' => null,
                'error' => 'Operator binding points at a missing knowledge entry.',
            ];
        }

        if ($entry->type !== 'person') {
            return [
                'status' => KnowledgeIdentityStatus::Inconsistent,
                'entry' => null,
                'error' => 'Operator binding must point at a person, not a '.$entry->type.'.',
            ];
        }

        $canonical = $this->followRedirects($entry);

        if ($canonical === null) {
            return [
                'status' => KnowledgeIdentityStatus::Inconsistent,
                'entry' => null,
                'error' => 'Operator binding redirect cycle.',
            ];
        }

        return [
            'status' => KnowledgeIdentityStatus::Resolved,
            'entry' => $canonical,
            'error' => null,
        ];
    }

    /**
     * Global-only resolution for {@see KnowledgeEntry::lookup()}. Ignores
     * agent-scoped titles so a compatibility caller cannot inherit request state.
     */
    public function lookupGlobal(string $name): ?KnowledgeEntry
    {
        $result = $this->resolve(KnowledgeIdentityContext::global(), query: $name);

        return $result->found() ? $result->entry : null;
    }

    private function resolveName(
        KnowledgeIdentityContext $context,
        string $query,
        bool $wantsOperator,
    ): KnowledgeIdentityResult {
        $normalized = KnowledgeName::normalize($query);

        if ($normalized === '') {
            return KnowledgeIdentityResult::notFound($query);
        }

        $matches = collect();

        if ($wantsOperator || KnowledgeName::isOperatorToken($normalized)) {
            $operator = $this->operatorBinding();

            if ($operator['status'] === KnowledgeIdentityStatus::Inconsistent) {
                return KnowledgeIdentityResult::inconsistent($query, (string) $operator['error']);
            }

            if ($operator['entry'] !== null) {
                $matches->push(new KnowledgeIdentityMatch(
                    entry: $operator['entry'],
                    matchedBy: self::MATCH_OPERATOR,
                    scope: self::SCOPE_INSTALLATION,
                    input: $query,
                    priority: 10,
                ));
            }
        }

        if ($context->agent !== null) {
            $matches = $matches->concat($this->agentReferenceMatches($context->agent, $normalized, $query, self::MATCH_AGENT_REFERENCE, self::SCOPE_AGENT, 20));
        }

        if ($context->authorAgent !== null && $context->authorAgent->id !== $context->agent?->id) {
            $matches = $matches->concat($this->agentReferenceMatches($context->authorAgent, $normalized, $query, self::MATCH_AUTHOR_REFERENCE, self::SCOPE_AUTHOR, 25));
        }

        $matches = $matches
            ->concat($this->canonicalMatches($normalized, $query))
            ->concat($this->identifierMatches($normalized, $query))
            ->concat($this->legacyAliasMatches($normalized, $query));

        if ($matches->isEmpty()) {
            return KnowledgeIdentityResult::notFound($query);
        }

        $canonicalized = $this->canonicalizeMatches($matches);

        if ($canonicalized instanceof KnowledgeIdentityResult) {
            return $canonicalized;
        }

        $byId = $canonicalized->groupBy(fn (KnowledgeIdentityMatch $match) => $match->entry->id);

        if ($byId->count() > 1) {
            $candidates = $byId
                ->map(fn (Collection $group) => $group->sortBy('priority')->first()->candidate())
                ->values()
                ->all();

            return KnowledgeIdentityResult::ambiguous($query, $candidates);
        }

        /** @var KnowledgeIdentityMatch $best */
        $best = $byId->first()->sortBy('priority')->first();

        return KnowledgeIdentityResult::resolved($best->entry, $best->matchedBy, $best->scope, $best->input);
    }

    /**
     * @return Collection<int, KnowledgeIdentityMatch>
     */
    private function agentReferenceMatches(
        Agent $agent,
        string $normalized,
        string $query,
        string $matchedBy,
        string $scope,
        int $priority,
    ): Collection {
        return AgentEntityReference::query()
            ->with('entry')
            ->where('agent_id', $agent->id)
            ->where('normalized_value', $normalized)
            ->get()
            ->filter(fn (AgentEntityReference $ref) => $ref->entry !== null)
            ->map(fn (AgentEntityReference $ref) => new KnowledgeIdentityMatch(
                entry: $ref->entry,
                matchedBy: $matchedBy,
                scope: $scope,
                input: $query,
                priority: $priority,
            ))
            ->values();
    }

    /**
     * @return Collection<int, KnowledgeIdentityMatch>
     */
    private function canonicalMatches(string $normalized, string $query): Collection
    {
        $entries = KnowledgeEntry::query()
            ->where('name_lower', $normalized)
            ->get();

        return $entries->map(fn (KnowledgeEntry $entry) => new KnowledgeIdentityMatch(
            entry: $entry,
            matchedBy: self::MATCH_CANONICAL,
            scope: self::SCOPE_GLOBAL,
            input: $query,
            priority: 30,
        ))->values();
    }

    /**
     * @return Collection<int, KnowledgeIdentityMatch>
     */
    private function identifierMatches(string $normalized, string $query): Collection
    {
        return KnowledgeIdentifier::query()
            ->with('entry')
            ->where('normalized_value', $normalized)
            ->get()
            ->filter(fn (KnowledgeIdentifier $identifier) => $identifier->entry !== null)
            ->map(fn (KnowledgeIdentifier $identifier) => new KnowledgeIdentityMatch(
                entry: $identifier->entry,
                matchedBy: self::MATCH_IDENTIFIER,
                scope: self::SCOPE_GLOBAL,
                input: $query,
                priority: 40,
            ))
            ->values();
    }

    /**
     * JSON aliases remain readable until every writer projects them from
     * knowledge_identifiers. Used only when the identifier table has no hit
     * for that row — still reports ambiguity instead of first().
     *
     * @return Collection<int, KnowledgeIdentityMatch>
     */
    private function legacyAliasMatches(string $normalized, string $query): Collection
    {
        $known = KnowledgeIdentifier::query()
            ->where('normalized_value', $normalized)
            ->pluck('entry_id')
            ->all();

        return KnowledgeEntry::query()
            ->where('aliases', 'like', '%'.$query.'%')
            ->when($known !== [], fn ($q) => $q->whereNotIn('id', $known))
            ->get()
            ->filter(fn (KnowledgeEntry $entry) => collect($entry->aliases ?? [])
                ->contains(fn (string $alias) => KnowledgeName::normalize($alias) === $normalized))
            ->map(fn (KnowledgeEntry $entry) => new KnowledgeIdentityMatch(
                entry: $entry,
                matchedBy: self::MATCH_LEGACY_ALIAS,
                scope: self::SCOPE_GLOBAL,
                input: $query,
                priority: 50,
            ))
            ->values();
    }

    /**
     * @param  Collection<int, KnowledgeIdentityMatch>  $matches
     * @return Collection<int, KnowledgeIdentityMatch>|KnowledgeIdentityResult
     */
    private function canonicalizeMatches(Collection $matches): Collection|KnowledgeIdentityResult
    {
        $out = collect();

        foreach ($matches as $match) {
            $canonical = $this->followRedirects($match->entry);

            if ($canonical === null) {
                return KnowledgeIdentityResult::inconsistent(
                    $match->input,
                    'Redirect cycle detected for entry '.$match->entry->id.'.',
                );
            }

            $out->push(new KnowledgeIdentityMatch(
                entry: $canonical,
                matchedBy: $canonical->id === $match->entry->id ? $match->matchedBy : self::MATCH_REDIRECT,
                scope: $canonical->id === $match->entry->id ? $match->scope : self::SCOPE_REDIRECT,
                input: $match->input,
                priority: $canonical->id === $match->entry->id ? $match->priority : 15,
            ));
        }

        return $out;
    }

    private function followRedirects(KnowledgeEntry $entry): ?KnowledgeEntry
    {
        $seen = [$entry->id => true];
        $current = $entry;

        for ($i = 0; $i < 32; $i++) {
            $redirect = KnowledgeEntryRedirect::query()
                ->with('toEntry')
                ->where('from_entry_id', $current->id)
                ->first();

            if ($redirect === null) {
                return $current;
            }

            if ($redirect->toEntry === null || isset($seen[$redirect->to_entry_id])) {
                return null;
            }

            $seen[$redirect->to_entry_id] = true;
            $current = $redirect->toEntry;
        }

        return null;
    }
}
