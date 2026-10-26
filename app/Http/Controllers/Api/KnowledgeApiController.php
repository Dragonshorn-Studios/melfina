<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeEntryRedirect;
use App\Models\KnowledgeIdentityStatus;
use App\Models\KnowledgeLink;
use App\Models\KnowledgeObservationKind;
use App\Models\KnowledgePredicate;
use App\Models\User;
use App\Services\KnowledgeEntryWriter;
use App\Services\KnowledgeGraph;
use App\Services\KnowledgeIdentityContext;
use App\Services\KnowledgeIdentityResolver;
use App\Services\KnowledgeIdentityResult;
use App\Support\KnowledgeName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * JSON API for the knowledge graph - used by the interactive graph viewer.
 */
class KnowledgeApiController extends Controller
{
    /**
     * The owner is one identity in the audit log, exactly as the console
     * pages record it. Confidence is keyed per author string, so a display
     * name here would split the owner's history in two.
     */
    private const AUTHOR = 'owner';

    /**
     * Get the full graph or a focused constellation.
     */
    public function graph(Request $request, KnowledgeGraph $graph): JsonResponse
    {
        $focus = $request->integer('focus') ?: null;
        $hops = max(1, min(5, $request->integer('hops', 2)));
        $limit = max(1, min(500, $request->integer('limit', 250)));

        $snapshot = $graph->constellation($focus, $hops, $limit);

        return response()->json($snapshot);
    }

    /**
     * Get a single knowledge entry.
     */
    public function entry(KnowledgeEntry $entry): JsonResponse
    {
        return response()->json([
            'id' => $entry->id,
            'name' => $entry->name,
            'type' => $entry->type,
            'summary' => $entry->summary,
            'state' => $entry->state,
            'source' => $entry->source,
            'verified' => $entry->verified_at !== null,
            'verified_at' => $entry->verified_at?->toIso8601String(),
            'version' => $entry->version,
            'updated_at' => $entry->updated_at->toIso8601String(),
            'updated_by' => $entry->updated_by,
        ]);
    }

    /**
     * Create a new knowledge entry.
     */
    public function storeEntry(
        Request $request,
        KnowledgeEntryWriter $writer,
        KnowledgeIdentityResolver $resolver,
    ): JsonResponse {
        $validated = $request->validate(self::entryRules());

        $name = trim($validated['name']);
        $nameLower = KnowledgeName::normalize($name);

        if (KnowledgeName::isOperatorToken($nameLower)) {
            throw ValidationException::withMessages([
                'name' => 'That name is reserved for the installation operator.',
            ]);
        }

        $collision = self::nameCollision($nameLower);

        if ($collision !== null) {
            throw ValidationException::withMessages([
                'name' => "'{$collision->name}' already uses this name.",
            ]);
        }

        // The writer resolves a name through aliases, identifiers and the
        // operator binding, and then *updates* whatever it matched. Resolve
        // first so a create can never land on an entity the caller did not
        // name.
        $resolved = $resolver->resolve(KnowledgeIdentityContext::forOwner(), query: $name);

        if ($resolved->found() && $resolved->entry !== null) {
            throw ValidationException::withMessages([
                'name' => "'{$resolved->entry->name}' already exists — update that entry instead of creating a second one.",
            ]);
        }

        if ($resolved->status !== KnowledgeIdentityStatus::NotFound) {
            throw ValidationException::withMessages([
                'name' => $resolved->error ?? 'Could not create the entry.',
            ]);
        }

        $result = $writer->upsert(
            context: KnowledgeIdentityContext::forOwner(),
            author: self::AUTHOR,
            authorKind: KnowledgeAuthorKind::Owner,
            entryName: $name,
            entryId: null,
            type: $validated['type'],
            aliases: null,
            related: $request->has('related') ? self::lines($validated['related'] ?? null) : null,
            fields: [
                'summary' => $validated['summary'] ?? '',
                'state' => $validated['state'] ?? '',
                'source' => $validated['source'] ?? '',
            ],
            expectedVersion: null,
            verified: false,
            allowRename: false,
            renameTo: null,
        );

        if (! $result->ok || $result->entry === null) {
            throw ValidationException::withMessages([
                'name' => $result->error ?? 'Could not save the entry.',
            ]);
        }

        if (! $result->created) {
            // Guard: the pre-check above refuses every name that resolves to
            // an existing entry, so this only fires if the entry appeared
            // between the two resolutions. Say what actually happened
            // rather than claiming nothing was written.
            throw ValidationException::withMessages([
                'name' => "'{$result->entry->name}' already existed — the write updated that entry instead of creating a new one.",
            ]);
        }

        $unresolved = $this->unresolvedRelated($result->entry, self::lines($validated['related'] ?? null));

        return response()->json([
            'entry' => self::entryPayload($result->entry),
            'unresolved_related' => $unresolved,
            'message' => $unresolved === []
                ? 'Entry created.'
                : 'Entry created, but these related names did not resolve: '.implode(', ', $unresolved).'.',
        ], 201);
    }

    /**
     * Update a knowledge entry.
     */
    public function updateEntry(Request $request, KnowledgeEntry $entry, KnowledgeEntryWriter $writer): JsonResponse
    {
        $validated = $request->validate(self::entryRules() + [
            'verified' => ['nullable', 'boolean'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);

        $name = trim($validated['name']);
        $nameLower = KnowledgeName::normalize($name);

        $collision = self::nameCollision($nameLower, $entry->id);

        if ($collision !== null) {
            throw ValidationException::withMessages([
                'name' => "'{$collision->name}' already uses this name.",
            ]);
        }

        $result = $writer->upsert(
            context: KnowledgeIdentityContext::forOwner(),
            author: self::AUTHOR,
            authorKind: KnowledgeAuthorKind::Owner,
            entryName: $entry->name,
            entryId: $entry->id,
            type: $validated['type'],
            aliases: null,
            related: $request->has('related') ? self::lines($validated['related'] ?? null) : null,
            fields: [
                'summary' => $validated['summary'] ?? '',
                'state' => $validated['state'] ?? '',
                'source' => $validated['source'] ?? '',
            ],
            expectedVersion: $request->filled('expected_version') ? $request->integer('expected_version') : null,
            verified: $request->boolean('verified'),
            allowRename: true,
            renameTo: $name,
        );

        if ($result->conflict) {
            throw ValidationException::withMessages([
                'expected_version' => "The entry changed since this page loaded (now v{$result->currentVersion}) — reload and merge your edit.",
            ]);
        }

        if (! $result->ok || $result->entry === null) {
            throw ValidationException::withMessages([
                'name' => $result->error ?? 'Could not save the entry.',
            ]);
        }

        return response()->json([
            'entry' => self::entryPayload($result->entry),
            'message' => 'Entry updated.',
        ]);
    }

    /**
     * Delete a knowledge entry.
     */
    public function deleteEntry(KnowledgeEntry $entry): JsonResponse
    {
        // Check if it's safe to delete
        if (User::query()->where('operator_entry_id', $entry->id)->exists()) {
            throw ValidationException::withMessages([
                'delete' => 'Detach or replace the operator binding before deleting this person.',
            ]);
        }

        if (KnowledgeEntryRedirect::query()
            ->where('from_entry_id', $entry->id)
            ->orWhere('to_entry_id', $entry->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'delete' => 'This entity is part of an identity redirect. Remove the redirect first.',
            ]);
        }

        $name = $entry->name;

        if (! $entry->delete()) {
            throw ValidationException::withMessages([
                'delete' => 'Failed to delete entry. Please try again.',
            ]);
        }

        return response()->json([
            'message' => "Entry '{$name}' deleted.",
            'success' => true,
        ]);
    }

    /**
     * Get a single link.
     */
    public function link(KnowledgeLink $link): JsonResponse
    {
        $link->load(['fromEntry', 'toEntry']);

        return response()->json(self::linkPayload($link));
    }

    /**
     * Create a new link.
     */
    public function storeLink(Request $request, KnowledgeGraph $graph): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:200'],
            'to' => ['required', 'string', 'max:200'],
            'predicate' => ['required', 'string', Rule::in(KnowledgePredicate::values())],
            'note' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'string', 'max:500'],
            'create_missing' => ['nullable', 'boolean'],
        ]);

        $result = $graph->connect(
            author: self::AUTHOR,
            authorKind: KnowledgeAuthorKind::Owner,
            from: $validated['from'],
            to: $validated['to'],
            predicate: KnowledgePredicate::from($validated['predicate']),
            note: $validated['note'] ?? null,
            source: $validated['source'] ?? null,
            createMissing: $request->boolean('create_missing'),
            identity: KnowledgeIdentityContext::forOwner(),
        );

        if (! $result->ok) {
            $message = $result->error ?? 'Could not write the link.';
            if ($result->unresolved !== null) {
                $missing = implode(' / ', $result->unresolved);
                $message = "Unknown entity ({$missing}). Create it first, or check create-if-missing.";
            }

            throw ValidationException::withMessages([
                'link' => $message,
            ]);
        }

        $result->link->load(['fromEntry', 'toEntry']);

        return response()->json([
            'link' => self::linkPayload($result->link, withStatus: false),
            'message' => 'Link created.',
        ], 201);
    }

    /**
     * Update a link (fix endpoints or predicate).
     */
    public function updateLink(Request $request, KnowledgeLink $link, KnowledgeGraph $graph): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:200'],
            'to' => ['required', 'string', 'max:200'],
            'predicate' => ['required', 'string', Rule::in(KnowledgePredicate::values())],
        ]);

        $resolver = app(KnowledgeIdentityResolver::class);

        $from = $resolver->resolve(KnowledgeIdentityContext::forOwner(), query: $validated['from']);
        $to = $resolver->resolve(KnowledgeIdentityContext::forOwner(), query: $validated['to']);

        $endError = $this->linkEndError($from) ?? $this->linkEndError($to);
        if ($endError !== null || $from->entry === null || $to->entry === null) {
            throw ValidationException::withMessages([
                'link' => $endError ?? 'Both ends must already exist to fix a link.',
            ]);
        }

        $result = $graph->retarget(
            $link,
            KnowledgePredicate::from($validated['predicate']),
            $from->entry,
            $to->entry,
        );

        if (! $result->ok) {
            throw ValidationException::withMessages([
                'link' => $result->error ?? 'Could not fix the link.',
            ]);
        }

        $result->link->load(['fromEntry', 'toEntry']);

        return response()->json([
            'link' => self::linkPayload($result->link),
            'message' => 'Link updated.',
        ]);
    }

    /**
     * Confirm a link (owner attestation).
     */
    public function confirmLink(KnowledgeLink $link, KnowledgeGraph $graph): JsonResponse
    {
        $graph->confirm($link);

        return response()->json([
            'message' => 'Link confirmed.',
            'link' => [
                'id' => $link->id,
                'confidence' => $link->fresh()->confidence,
            ],
        ]);
    }

    /**
     * Set permanent status on a link.
     */
    public function permanentLink(Request $request, KnowledgeLink $link, KnowledgeGraph $graph): JsonResponse
    {
        $lock = $request->boolean('permanent', true);
        $graph->setPermanent($link, $lock);
        $link->refresh();

        return response()->json([
            'message' => $lock ? 'Link marked permanent.' : 'Permanent lock lifted.',
            'link' => [
                'id' => $link->id,
                'permanent' => $link->permanent,
                'confidence' => $link->confidence,
            ],
        ]);
    }

    /**
     * Retract a link.
     */
    public function retractLink(Request $request, KnowledgeLink $link, KnowledgeGraph $graph): JsonResponse
    {
        $result = $graph->connect(
            author: self::AUTHOR,
            authorKind: KnowledgeAuthorKind::Owner,
            from: $link->fromEntry->name,
            to: $link->toEntry->name,
            predicate: $link->predicate,
            op: KnowledgeObservationKind::LinkRetract,
            identity: KnowledgeIdentityContext::forOwner(),
            fromId: $link->from_entry_id,
            toId: $link->to_entry_id,
        );

        if (! $result->ok || $result->link === null) {
            throw ValidationException::withMessages([
                'link' => $result->error ?? 'Could not retract the link.',
            ]);
        }

        return response()->json([
            'message' => 'Link retracted.',
            'link' => [
                'id' => $result->link->id,
                'status' => $result->link->status->value,
                'confidence' => $result->link->confidence,
            ],
        ]);
    }

    /**
     * Delete a link.
     */
    public function deleteLink(KnowledgeLink $link): JsonResponse
    {
        if (! $link->delete()) {
            throw ValidationException::withMessages([
                'delete' => 'Failed to delete link. Please try again.',
            ]);
        }

        return response()->json([
            'message' => 'Link deleted.',
            'success' => true,
        ]);
    }

    /**
     * Get predicates for dropdowns.
     */
    public function predicates(): JsonResponse
    {
        return response()->json([
            'predicates' => KnowledgePredicate::values(),
        ]);
    }

    /**
     * Get entry types for dropdowns.
     */
    public function types(): JsonResponse
    {
        return response()->json([
            'types' => KnowledgeEntry::TYPES,
        ]);
    }

    /**
     * Search for entries by name.
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->string('q', '');
        $limit = max(1, min(100, $request->integer('limit', 10)));
        $page = max(1, $request->integer('page', 1));

        // Normalise the same way the stored name_lower keys are built, and
        // escape the LIKE wildcards so "100%" cannot match every row.
        $searchTerm = '%'.self::likePattern(KnowledgeName::normalize($query)).'%';

        $entries = KnowledgeEntry::query()
            ->whereRaw('name_lower LIKE ? ESCAPE ?', [$searchTerm, '\\'])
            ->orderBy('name_lower')
            ->paginate($limit, ['id', 'name', 'type'], 'page', $page);

        return response()->json([
            'results' => $entries->items(),
            'query' => $query,
            'limit' => $limit,
            'page' => $page,
            'total' => $entries->total(),
            'last_page' => $entries->lastPage(),
        ]);
    }

    /**
     * The entry shape the API answers with after a write.
     *
     * @return array<string, mixed>
     */
    private static function entryPayload(KnowledgeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'name' => $entry->name,
            'type' => $entry->type,
            'summary' => $entry->summary,
            'version' => $entry->version,
        ];
    }

    /**
     * The link shape the API answers with. The status is opt-in: a link
     * that was just written has nothing to report yet.
     *
     * @return array<string, mixed>
     */
    private static function linkPayload(KnowledgeLink $link, bool $withStatus = true): array
    {
        $payload = [
            'id' => $link->id,
            'from' => $link->fromEntry->name,
            'from_id' => $link->from_entry_id,
            'to' => $link->toEntry->name,
            'to_id' => $link->to_entry_id,
            'predicate' => $link->predicate->value,
            'confidence' => $link->confidence,
            'permanent' => $link->permanent,
            'disputed' => $link->disputed,
        ];

        if ($withStatus) {
            $payload['status'] = $link->status->value;
        }

        return $payload;
    }

    /**
     * The rules every entry write shares.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function entryRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', 'string', Rule::in(KnowledgeEntry::TYPES)],
            'summary' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string', 'max:20000'],
            'related' => ['nullable', 'string', 'max:4200'],
            'source' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The entry that already owns this name, if any. An update ignores the
     * entry it is updating.
     */
    private static function nameCollision(string $nameLower, ?int $exceptId = null): ?KnowledgeEntry
    {
        $query = KnowledgeEntry::query()->where('name_lower', $nameLower);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->first();
    }

    /**
     * The related names that did not become links. A name that does not
     * resolve is dropped by the writer, so the owner has to be told rather
     * than left with a relation that exists in one column and not the other.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function unresolvedRelated(KnowledgeEntry $entry, array $requested): array
    {
        if ($requested === []) {
            return [];
        }

        $linked = KnowledgeLink::query()
            ->where('from_entry_id', $entry->id)
            ->where('predicate', KnowledgePredicate::RelatedTo->value)
            ->with('toEntry')
            ->get()
            ->pluck('toEntry')
            ->filter()
            ->map(fn (KnowledgeEntry $target) => KnowledgeName::normalize($target->name))
            ->all();

        $unresolved = [];

        foreach ($requested as $name) {
            if (! in_array(KnowledgeName::normalize($name), $linked, true)) {
                $unresolved[] = $name;
            }
        }

        return $unresolved;
    }

    /**
     * Escape the LIKE metacharacters so a query is matched literally.
     */
    private static function likePattern(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * The console edits alias/related lists as one-per-line text.
     *
     * @return list<string>
     */
    private static function lines(?string $raw): array
    {
        return collect(preg_split('/\R/', (string) $raw))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    private function linkEndError(KnowledgeIdentityResult $resolved): ?string
    {
        if ($resolved->found()) {
            return null;
        }

        if ($resolved->status === KnowledgeIdentityStatus::Ambiguous) {
            return "'{$resolved->input}' matches more than one entity. Use a unique name or resolve the collision first.";
        }

        if (is_string($resolved->error) && $resolved->error !== '') {
            return $resolved->error;
        }

        return 'Both ends must already exist to fix a link.';
    }
}
