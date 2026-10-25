<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeLink;
use App\Models\KnowledgeObservationKind;
use App\Models\KnowledgePredicate;
use App\Services\KnowledgeEntryWriter;
use App\Services\KnowledgeGraph;
use App\Services\KnowledgeIdentityContext;
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
     * Get the full graph or a focused constellation.
     */
    public function graph(Request $request, KnowledgeGraph $graph): JsonResponse
    {
        $focus = $request->integer('focus') ?: null;
        $hops = $request->integer('hops', 2);
        $limit = $request->integer('limit', 250);

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
            'verified' => $entry->verified,
            'version' => $entry->version,
            'updated_at' => $entry->updated_at->toIso8601String(),
            'updated_by' => $entry->updated_by,
        ]);
    }

    /**
     * Create a new knowledge entry.
     */
    public function storeEntry(Request $request, KnowledgeEntryWriter $writer): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', 'string', Rule::in(KnowledgeEntry::TYPES)],
            'summary' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string', 'max:20000'],
            'related' => ['nullable', 'string', 'max:4200'],
            'source' => ['nullable', 'string', 'max:500'],
        ]);

        $name = trim($validated['name']);
        $nameLower = KnowledgeName::normalize($name);

        $collision = KnowledgeEntry::query()
            ->where('name_lower', $nameLower)
            ->first();

        if ($collision !== null) {
            throw ValidationException::withMessages([
                'name' => "'{$collision->name}' already uses this name.",
            ]);
        }

        $result = $writer->upsert(
            context: KnowledgeIdentityContext::forOwner(),
            author: 'owner',
            authorKind: KnowledgeAuthorKind::Owner,
            entryName: $name,
            entryId: null,
            type: $validated['type'],
            aliases: null,
            related: self::lines($validated['related'] ?? null),
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

        return response()->json([
            'entry' => [
                'id' => $result->entry->id,
                'name' => $result->entry->name,
                'type' => $result->entry->type,
                'summary' => $result->entry->summary,
                'version' => $result->entry->version,
            ],
            'message' => 'Entry created.',
        ], 201);
    }

    /**
     * Update a knowledge entry.
     */
    public function updateEntry(Request $request, KnowledgeEntry $entry, KnowledgeEntryWriter $writer): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', 'string', Rule::in(KnowledgeEntry::TYPES)],
            'summary' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string', 'max:20000'],
            'related' => ['nullable', 'string', 'max:4200'],
            'source' => ['nullable', 'string', 'max:500'],
            'verified' => ['nullable', 'boolean'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);

        $name = trim($validated['name']);
        $nameLower = KnowledgeName::normalize($name);

        $collision = KnowledgeEntry::query()
            ->where('name_lower', $nameLower)
            ->whereKeyNot($entry->id)
            ->first();

        if ($collision !== null) {
            throw ValidationException::withMessages([
                'name' => "'{$collision->name}' already uses this name.",
            ]);
        }

        $result = $writer->upsert(
            context: KnowledgeIdentityContext::forOwner(),
            author: 'owner',
            authorKind: KnowledgeAuthorKind::Owner,
            entryName: $entry->name,
            entryId: $entry->id,
            type: $validated['type'],
            aliases: null,
            related: self::lines($validated['related'] ?? null),
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
            'entry' => [
                'id' => $result->entry->id,
                'name' => $result->entry->name,
                'type' => $result->entry->type,
                'summary' => $result->entry->summary,
                'version' => $result->entry->version,
            ],
            'message' => 'Entry updated.',
        ]);
    }

    /**
     * Delete a knowledge entry.
     */
    public function deleteEntry(KnowledgeEntry $entry): JsonResponse
    {
        // Check if it's safe to delete
        if (\App\Models\User::query()->where('operator_entry_id', $entry->id)->exists()) {
            throw ValidationException::withMessages([
                'delete' => 'Detach or replace the operator binding before deleting this person.',
            ]);
        }

        if (\App\Models\KnowledgeEntryRedirect::query()
            ->where('from_entry_id', $entry->id)
            ->orWhere('to_entry_id', $entry->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'delete' => 'This entity is part of an identity redirect. Remove the redirect first.',
            ]);
        }

        $name = $entry->name;
        $entry->delete();

        return response()->json([
            'message' => "Entry '{$name}' deleted.",
        ]);
    }

    /**
     * Get a single link.
     */
    public function link(KnowledgeLink $link): JsonResponse
    {
        $link->load(['fromEntry', 'toEntry']);

        return response()->json([
            'id' => $link->id,
            'from' => $link->fromEntry->name,
            'from_id' => $link->from_entry_id,
            'to' => $link->toEntry->name,
            'to_id' => $link->to_entry_id,
            'predicate' => $link->predicate->value,
            'confidence' => $link->confidence,
            'permanent' => $link->permanent,
            'disputed' => $link->disputed,
            'status' => $link->status->value,
        ]);
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
            author: 'owner',
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
            'link' => [
                'id' => $result->link->id,
                'from' => $result->link->fromEntry->name,
                'from_id' => $result->link->from_entry_id,
                'to' => $result->link->toEntry->name,
                'to_id' => $result->link->to_entry_id,
                'predicate' => $result->link->predicate->value,
                'confidence' => $result->link->confidence,
                'permanent' => $result->link->permanent,
                'disputed' => $result->link->disputed,
            ],
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

        $from = app(\App\Services\KnowledgeIdentityResolver::class)
            ->resolve(KnowledgeIdentityContext::forOwner(), query: $validated['from']);
        $to = app(\App\Services\KnowledgeIdentityResolver::class)
            ->resolve(KnowledgeIdentityContext::forOwner(), query: $validated['to']);

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
            'link' => [
                'id' => $result->link->id,
                'from' => $result->link->fromEntry->name,
                'from_id' => $result->link->from_entry_id,
                'to' => $result->link->toEntry->name,
                'to_id' => $result->link->to_entry_id,
                'predicate' => $result->link->predicate->value,
                'confidence' => $result->link->confidence,
                'permanent' => $result->link->permanent,
                'disputed' => $result->link->disputed,
            ],
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
    public function retractLink(KnowledgeLink $link, KnowledgeGraph $graph): JsonResponse
    {
        $fromId = $link->from_entry_id;
        $graph->connect(
            author: 'owner',
            authorKind: KnowledgeAuthorKind::Owner,
            from: $link->fromEntry->name,
            to: $link->toEntry->name,
            predicate: $link->predicate,
            op: KnowledgeObservationKind::LinkRetract,
            identity: KnowledgeIdentityContext::forOwner(),
            fromId: $link->from_entry_id,
            toId: $link->to_entry_id,
        );

        return response()->json([
            'message' => 'Link retracted.',
            'link' => [
                'id' => $link->id,
                'status' => 'retracted',
            ],
        ]);
    }

    /**
     * Delete a link.
     */
    public function deleteLink(KnowledgeLink $link): JsonResponse
    {
        $link->delete();

        return response()->json([
            'message' => 'Link deleted.',
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
        $limit = $request->integer('limit', 10);

        $entries = KnowledgeEntry::query()
            ->where('name_lower', 'like', '%'.strtolower($query).'%')
            ->orderBy('name_lower')
            ->limit($limit)
            ->get(['id', 'name', 'type']);

        return response()->json([
            'results' => $entries,
        ]);
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

    private function linkEndError(\App\Services\KnowledgeIdentityResult $resolved): ?string
    {
        if ($resolved->found()) {
            return null;
        }

        if ($resolved->status === \App\Models\KnowledgeIdentityStatus::Ambiguous) {
            return "'{$resolved->input}' matches more than one entity. Use a unique name or resolve the collision first.";
        }

        if (is_string($resolved->error) && $resolved->error !== '') {
            return $resolved->error;
        }

        return 'Both ends must already exist to fix a link.';
    }
}
