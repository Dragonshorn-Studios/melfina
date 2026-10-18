<?php

namespace App\Services;

use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeIdentifierKind;
use App\Models\KnowledgeObservation;
use App\Models\KnowledgeObservationKind;
use App\Models\KnowledgePredicate;
use App\Models\User;
use App\Support\KnowledgeName;
use Illuminate\Support\Facades\DB;

/**
 * Shared create/update path for MCP knowledge_upsert and the owner console.
 * Resolves the target, compare-and-swaps version on SQLite by checking the
 * affected-row count, snapshots, records an entity_upsert observation, and
 * dual-writes related names in one transaction.
 */
class KnowledgeEntryWriter
{
    public function __construct(
        private KnowledgeIdentityResolver $resolver,
        private KnowledgeGraph $graph,
    ) {}

    /**
     * @param  list<string>|null  $aliases
     * @param  list<string>|null  $related
     * @param  array<string, mixed>  $fields
     */
    public function upsert(
        KnowledgeIdentityContext $context,
        string $author,
        KnowledgeAuthorKind $authorKind,
        string $entryName,
        ?int $entryId = null,
        ?string $type = null,
        ?array $aliases = null,
        ?array $related = null,
        array $fields = [],
        ?int $expectedVersion = null,
        bool $verified = false,
        bool $allowRename = false,
        ?string $renameTo = null,
    ): KnowledgeWriteResult {
        $entryName = trim($entryName);

        if ($entryName === '') {
            return new KnowledgeWriteResult(ok: false, error: 'Entry name is required.', code: 'not_found');
        }

        if ($entryId !== null && $this->resolver->isRedirectSource($entryId)) {
            $canonical = $this->resolver->resolveId($entryId);

            return new KnowledgeWriteResult(
                ok: false,
                entry: $canonical->entry,
                error: $canonical->error ?? 'This entry was merged; write the canonical id.',
                code: 'redirected_entry',
            );
        }

        $resolved = $this->resolver->resolve($context, query: $entryName, entryId: $entryId);

        if (in_array($resolved->status->value, ['ambiguous', 'inconsistent', 'conflict'], true)) {
            return new KnowledgeWriteResult(
                ok: false,
                error: $resolved->error,
                code: $resolved->code,
                candidates: $resolved->candidates,
            );
        }

        $existing = $resolved->found() ? $resolved->entry : null;

        if ($existing === null && $type === null) {
            return new KnowledgeWriteResult(
                ok: false,
                error: 'New entries need a type: '.implode(', ', KnowledgeEntry::TYPES).'.',
                code: 'type_required',
            );
        }

        if ($existing !== null && $expectedVersion !== null && $existing->version !== $expectedVersion) {
            return new KnowledgeWriteResult(
                ok: false,
                entry: $existing,
                conflict: true,
                currentVersion: $existing->version,
                error: 'The entry changed since you read it — re-read it with knowledge_lookup and merge before writing.',
                code: 'version_conflict',
            );
        }

        try {
            $written = DB::transaction(function () use (
                $context,
                $author,
                $authorKind,
                $entryName,
                $type,
                $aliases,
                $related,
                $fields,
                $expectedVersion,
                $verified,
                $allowRename,
                $renameTo,
                $existing,
            ): KnowledgeEntry {
                if ($existing === null) {
                    return $this->createEntry(
                        $author,
                        $authorKind,
                        $entryName,
                        $type,
                        $aliases,
                        $related,
                        $fields,
                        $verified,
                        $context,
                    );
                }

                return $this->updateEntry(
                    $existing,
                    $author,
                    $authorKind,
                    $type,
                    $aliases,
                    $related,
                    $fields,
                    $expectedVersion ?? $existing->version,
                    $verified,
                    $allowRename,
                    $renameTo,
                    $context,
                );
            });
        } catch (KnowledgeVersionConflict $e) {
            return new KnowledgeWriteResult(
                ok: false,
                entry: $e->entry,
                conflict: true,
                currentVersion: $e->entry?->version,
                error: 'The entry changed since you read it — re-read it with knowledge_lookup and merge before writing.',
                code: 'version_conflict',
            );
        } catch (KnowledgeIdentityWriteException $e) {
            return new KnowledgeWriteResult(
                ok: false,
                error: $e->getMessage(),
                code: $e->identityCode,
                candidates: $e->candidates,
                unresolved: $e->unresolved,
            );
        }

        return new KnowledgeWriteResult(
            ok: true,
            entry: $written,
            created: $written->wasRecentlyCreated,
        );
    }

    /**
     * @param  list<string>|null  $aliases
     * @param  list<string>|null  $related
     * @param  array<string, mixed>  $fields
     */
    private function createEntry(
        string $author,
        KnowledgeAuthorKind $authorKind,
        string $name,
        string $type,
        ?array $aliases,
        ?array $related,
        array $fields,
        bool $verified,
        KnowledgeIdentityContext $context,
    ): KnowledgeEntry {
        $entry = new KnowledgeEntry([
            'name' => $name,
            'name_lower' => KnowledgeName::normalize($name),
            'type' => $type,
            'summary' => $fields['summary'] ?? '',
            'state' => $fields['state'] ?? '',
            'source' => $fields['source'] ?? null,
            'aliases' => [],
            'related' => $related ?? [],
            'updated_by' => $author,
            'version' => 1,
        ]);

        if ($verified) {
            $entry->verified_at = now();
        }

        $entry->save();
        $this->replaceAliasIdentifiers($entry, $aliases ?? [], $author);
        $entry->recordVersion($author);
        $this->recordUpsertObservation($entry, $author, $authorKind);
        $this->dualWriteRelated($entry, $related ?? [], $author, $authorKind, $context, $fields['source'] ?? null);

        return $entry;
    }

    /**
     * @param  list<string>|null  $aliases
     * @param  list<string>|null  $related
     * @param  array<string, mixed>  $fields
     */
    private function updateEntry(
        KnowledgeEntry $entry,
        string $author,
        KnowledgeAuthorKind $authorKind,
        ?string $type,
        ?array $aliases,
        ?array $related,
        array $fields,
        int $expectedVersion,
        bool $verified,
        bool $allowRename,
        ?string $renameTo,
        KnowledgeIdentityContext $context,
    ): KnowledgeEntry {
        $name = $entry->name;
        $nameLower = $entry->name_lower;

        if ($allowRename && $renameTo !== null && trim($renameTo) !== '') {
            $name = trim($renameTo);
            $nameLower = KnowledgeName::normalize($name);
        }

        $payload = [
            'name' => $name,
            'name_lower' => $nameLower,
            'updated_by' => $author,
            'version' => $expectedVersion + 1,
            'updated_at' => now(),
        ];

        if ($type !== null && $type !== $entry->type) {
            if ($authorKind === KnowledgeAuthorKind::Agent
                && User::query()->where('operator_entry_id', $entry->id)->exists()) {
                throw new KnowledgeIdentityWriteException(
                    'The installation operator is a person and cannot change type through an agent write.',
                    'operator_type_locked',
                );
            }

            $payload['type'] = $type;
        }

        foreach (['summary', 'state', 'source'] as $field) {
            if (array_key_exists($field, $fields)) {
                $payload[$field] = $fields[$field] ?? '';
            }
        }

        if ($related !== null) {
            $payload['related'] = json_encode(array_values($related));
        }

        if ($verified) {
            $payload['verified_at'] = now();
        }

        $affected = KnowledgeEntry::query()
            ->whereKey($entry->id)
            ->where('version', $expectedVersion)
            ->update($payload);

        if ($affected !== 1) {
            throw new KnowledgeVersionConflict($entry->fresh());
        }

        $entry->refresh();

        if ($aliases !== null) {
            $this->replaceAliasIdentifiers($entry, $aliases, $author);
            $entry->refresh();
        }

        $entry->recordVersion($author);
        $this->recordUpsertObservation($entry, $author, $authorKind);

        if ($related !== null) {
            $this->dualWriteRelated($entry, $related, $author, $authorKind, $context, $fields['source'] ?? null);
        }

        return $entry;
    }

    /**
     * Replacement semantics for ordinary aliases only. Persona/handle
     * identifiers and scoped agent references are left untouched.
     *
     * @param  list<string>  $aliases
     */
    public function replaceAliasIdentifiers(KnowledgeEntry $entry, array $aliases, string $author): void
    {
        $normalizedCanonical = $entry->name_lower;
        $wanted = [];

        foreach ($aliases as $alias) {
            if (! is_string($alias)) {
                continue;
            }

            $value = trim($alias);
            $normalized = KnowledgeName::normalize($value);

            if ($value === '' || $normalized === '' || $normalized === $normalizedCanonical) {
                continue;
            }

            if (KnowledgeName::isOperatorToken($normalized)) {
                throw new KnowledgeIdentityWriteException(
                    'The reserved operator reference cannot be stored as an alias.',
                    'reserved_operator',
                );
            }

            $wanted[$normalized] = $value;
        }

        $existing = $entry->identifiers()
            ->where('kind', KnowledgeIdentifierKind::Alias)
            ->get();

        foreach ($existing as $identifier) {
            if (! isset($wanted[$identifier->normalized_value])) {
                $identifier->delete();
            }
        }

        foreach ($wanted as $normalized => $value) {
            $already = $entry->identifiers()->where('normalized_value', $normalized)->first();

            if ($already !== null) {
                if ($already->kind === KnowledgeIdentifierKind::Alias) {
                    continue;
                }

                throw new KnowledgeIdentityWriteException(
                    "'{$value}' is already a {$already->kind->value} of this entry.",
                    'identifier_collision',
                );
            }

            $entry->identifiers()->create([
                'value' => $value,
                'normalized_value' => $normalized,
                'kind' => KnowledgeIdentifierKind::Alias,
                'author' => $author,
            ]);
        }

        $entry->syncAliasProjection();
    }

    /**
     * @param  list<string>  $related
     */
    private function dualWriteRelated(
        KnowledgeEntry $entry,
        array $related,
        string $author,
        KnowledgeAuthorKind $authorKind,
        KnowledgeIdentityContext $context,
        ?string $source,
    ): void {
        foreach ($related as $name) {
            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $result = $this->graph->connect(
                author: $author,
                authorKind: $authorKind,
                from: $entry->name,
                to: trim($name),
                predicate: KnowledgePredicate::RelatedTo,
                source: $source,
                identity: $context,
                fromId: $entry->id,
            );

            if (! $result->ok && $result->unresolved === null && $result->error !== null) {
                throw new KnowledgeIdentityWriteException(
                    $result->error,
                    $result->code ?? 'connect_failed',
                    $result->ambiguous ?? [],
                    $result->unresolved,
                );
            }
        }
    }

    private function recordUpsertObservation(
        KnowledgeEntry $entry,
        string $author,
        KnowledgeAuthorKind $authorKind,
    ): void {
        KnowledgeObservation::query()->create([
            'kind' => KnowledgeObservationKind::EntityUpsert,
            'entry_id' => $entry->id,
            'link_id' => null,
            'author' => $author,
            'author_kind' => $authorKind,
            'payload' => [
                'name' => $entry->name,
                'version' => $entry->version,
            ],
        ]);
    }
}
