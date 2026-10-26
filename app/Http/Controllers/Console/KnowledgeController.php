<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeEntryRedirect;
use App\Models\KnowledgeIdentifier;
use App\Models\KnowledgeIdentifierKind;
use App\Models\KnowledgeIdentityStatus;
use App\Models\KnowledgeLink;
use App\Models\KnowledgeObservationKind;
use App\Models\KnowledgePredicate;
use App\Models\User;
use App\Services\KnowledgeEntryWriter;
use App\Services\KnowledgeGraph;
use App\Services\KnowledgeIdentityAdmin;
use App\Services\KnowledgeIdentityContext;
use App\Services\KnowledgeIdentityResolver;
use App\Services\KnowledgeIdentityResult;
use App\Services\PersonaLinks;
use App\Support\KnowledgeName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Owner console for the shared knowledge base — the same entries agents
 * read and write through knowledge_lookup/search/upsert. List, search,
 * create, edit, version history, and delete live here; owner writes go
 * through the same versioned upsert path with author 'owner'.
 */
class KnowledgeController extends Controller
{
    /**
     * All entries plus a token-AND search across the same fields the
     * agent-facing knowledge_search scans.
     */
    public function index(Request $request): View
    {
        $tokens = collect(preg_split('/\s+/', KnowledgeName::normalize($request->string('q')->toString())))
            ->filter()->unique()->values();

        $entries = KnowledgeEntry::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->when($tokens->isNotEmpty(), function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    $q->where(function ($sub) use ($token) {
                        $sub->where('name_lower', 'like', '%'.$token.'%')
                            ->orWhere('aliases', 'like', '%'.$token.'%')
                            ->orWhere('summary', 'like', '%'.$token.'%')
                            ->orWhere('state', 'like', '%'.$token.'%')
                            ->orWhere('related', 'like', '%'.$token.'%');
                    });
                }
            })
            ->orderBy('name_lower')
            ->get();

        return view('console.knowledge.index', [
            'entries' => $entries,
            'types' => KnowledgeEntry::TYPES,
            'q' => $request->string('q')->toString(),
            'type' => $request->string('type')->toString(),
        ]);
    }

    public function create(): View
    {
        return view('console.knowledge.edit', [
            'entry' => null,
            'types' => KnowledgeEntry::TYPES,
            'versions' => collect(),
        ]);
    }

    public function store(Request $request, KnowledgeEntryWriter $writer): RedirectResponse
    {
        $entry = $this->save($request, null, $writer);

        if ($request->string('return')->toString() === 'graph') {
            return redirect()
                ->route('console.knowledge.graph', ['focus' => $entry->id])
                ->with('status', "Entity '{$entry->name}' created.");
        }

        return redirect()
            ->route('console.knowledge.edit', $entry)
            ->with('status', "Entry '{$entry->name}' created.");
    }

    public function edit(KnowledgeEntry $knowledge): View
    {
        return view('console.knowledge.edit', [
            'entry' => $knowledge,
            'types' => KnowledgeEntry::TYPES,
            'versions' => $knowledge->versions()->orderByDesc('version')->get(),
            'identifiers' => $knowledge->identifiers()->orderBy('kind')->orderBy('id')->get(),
            'isOperator' => User::query()->where('operator_entry_id', $knowledge->id)->exists(),
            'identifierKinds' => KnowledgeIdentifierKind::cases(),
            'personaLinks' => $knowledge->type === 'person'
                ? app(PersonaLinks::class)->component($knowledge)->where('id', '!=', $knowledge->id)->values()
                : collect(),
            'people' => KnowledgeEntry::query()->where('type', 'person')->whereKeyNot($knowledge->id)->orderBy('name_lower')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, KnowledgeEntry $knowledge, KnowledgeEntryWriter $writer): RedirectResponse
    {
        $entry = $this->save($request, $knowledge, $writer);

        return redirect()
            ->route('console.knowledge.edit', $entry)
            ->with('status', "Entry '{$entry->name}' saved — version {$entry->version}.");
    }

    /**
     * Delete an entry outright; its version history cascades with it.
     */
    public function destroy(KnowledgeEntry $knowledge): RedirectResponse
    {
        if (User::query()->where('operator_entry_id', $knowledge->id)->exists()) {
            return redirect()
                ->route('console.knowledge.edit', $knowledge)
                ->withErrors(['delete' => 'Detach or replace the operator binding before deleting this person.']);
        }

        if (KnowledgeEntryRedirect::query()
            ->where('from_entry_id', $knowledge->id)
            ->orWhere('to_entry_id', $knowledge->id)
            ->exists()) {
            return redirect()
                ->route('console.knowledge.edit', $knowledge)
                ->withErrors(['delete' => 'This entity is part of an identity redirect. Remove the redirect first.']);
        }

        $name = $knowledge->name;
        $knowledge->delete();

        return redirect()
            ->route('console.knowledge.index')
            ->with('status', "Entry '{$name}' deleted — its links and observations went with it.");
    }

    /**
     * Constellation explorer — the owner walks typed links, fixes them,
     * creates entities when needed, and locks an edge as permanent.
     */
    public function graph(Request $request, KnowledgeGraph $graph): View
    {
        $focus = $request->integer('focus') ?: null;

        return view('console.knowledge.graph', [
            'snapshot' => $graph->constellation($focus),
            'focusId' => $focus,
            'types' => KnowledgeEntry::TYPES,
            'predicates' => KnowledgePredicate::values(),
            'entries' => KnowledgeEntry::query()->orderBy('name_lower')->get(['id', 'name', 'type']),
        ]);
    }

    public function storeLink(Request $request, KnowledgeGraph $graph): RedirectResponse
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

            return redirect()
                ->route('console.knowledge.graph')
                ->withErrors(['link' => $message]);
        }

        $fromId = $result->link->from_entry_id;

        return redirect()
            ->route('console.knowledge.graph', ['focus' => $fromId, 'link' => $result->link->id])
            ->with('status', "Link {$result->link->predicate->value} recorded — confidence {$result->link->confidence}.");
    }

    public function confirmLink(KnowledgeLink $link, KnowledgeGraph $graph): RedirectResponse
    {
        $graph->confirm($link);

        return redirect()
            ->route('console.knowledge.graph', ['focus' => $link->from_entry_id, 'link' => $link->id])
            ->with('status', 'Link confirmed — owner floor.');
    }

    public function permanentLink(Request $request, KnowledgeLink $link, KnowledgeGraph $graph): RedirectResponse
    {
        $lock = $request->boolean('permanent', true);
        $graph->setPermanent($link, $lock);
        $link->refresh();

        return redirect()
            ->route('console.knowledge.graph', ['focus' => $link->from_entry_id, 'link' => $link->id])
            ->with('status', $lock ? 'Link marked permanent. Agents cannot retract it.' : 'Permanent lock lifted.');
    }

    public function retractLink(KnowledgeLink $link, KnowledgeGraph $graph): RedirectResponse
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

        return redirect()
            ->route('console.knowledge.graph', ['focus' => $fromId])
            ->with('status', 'Link retracted.');
    }

    public function updateLink(Request $request, KnowledgeLink $link, KnowledgeGraph $graph): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:200'],
            'to' => ['required', 'string', 'max:200'],
            'predicate' => ['required', 'string', Rule::in(KnowledgePredicate::values())],
        ]);

        $from = app(KnowledgeIdentityResolver::class)
            ->resolve(KnowledgeIdentityContext::forOwner(), query: $validated['from']);
        $to = app(KnowledgeIdentityResolver::class)
            ->resolve(KnowledgeIdentityContext::forOwner(), query: $validated['to']);
        $endError = $this->linkEndError($from) ?? $this->linkEndError($to);
        if ($endError !== null || $from->entry === null || $to->entry === null) {
            return redirect()
                ->route('console.knowledge.graph', ['focus' => $link->from_entry_id, 'link' => $link->id])
                ->withErrors(['link' => $endError ?? 'Both ends must already exist to fix a link.']);
        }

        $result = $graph->retarget(
            $link,
            KnowledgePredicate::from($validated['predicate']),
            $from->entry,
            $to->entry,
        );

        if (! $result->ok) {
            return redirect()
                ->route('console.knowledge.graph', ['focus' => $link->from_entry_id, 'link' => $link->id])
                ->withErrors(['link' => $result->error ?? 'Could not fix the link.']);
        }

        return redirect()
            ->route('console.knowledge.graph', ['focus' => $result->link->from_entry_id, 'link' => $result->link->id])
            ->with('status', 'Link fixed.');
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

    /**
     * The owner-side equivalent of knowledge_upsert: same field limits,
     * same versioned write, author stamped 'owner'. `expected_version`
     * (echoed by the edit form) fails instead of clobbering a change an
     * agent just wrote — same contract as the MCP tool.
     */
    public function bindOperator(KnowledgeEntry $knowledge, KnowledgeIdentityAdmin $admin, Request $request): RedirectResponse
    {
        $admin->bindOperator($knowledge, $request->user());

        return redirect()
            ->route('console.knowledge.edit', $knowledge)
            ->with('status', $knowledge->name.' is the installation operator.');
    }

    public function storeIdentifier(Request $request, KnowledgeEntry $knowledge, KnowledgeIdentityAdmin $admin): RedirectResponse
    {
        $validated = $request->validate([
            'value' => ['required', 'string', 'max:200'],
            'kind' => ['required', 'string', Rule::in(KnowledgeIdentifierKind::values())],
        ]);

        $admin->addIdentifier(
            $knowledge,
            $validated['value'],
            KnowledgeIdentifierKind::from($validated['kind']),
        );

        return redirect()
            ->back(fallback: route('console.knowledge.edit', $knowledge))
            ->with('status', 'Identifier saved.');
    }

    public function destroyIdentifier(
        KnowledgeEntry $knowledge,
        KnowledgeIdentifier $identifier,
        KnowledgeIdentityAdmin $admin,
    ): RedirectResponse {
        abort_unless($identifier->entry_id === $knowledge->id, 404);

        $admin->removeIdentifier($identifier);

        return redirect()
            ->back(fallback: route('console.knowledge.edit', $knowledge))
            ->with('status', 'Identifier removed.');
    }

    public function storePersona(Request $request, KnowledgeEntry $knowledge, PersonaLinks $personas): RedirectResponse
    {
        $validated = $request->validate([
            'persona_entry_id' => ['required', 'integer', 'exists:knowledge_entries,id'],
        ]);

        $other = KnowledgeEntry::query()->findOrFail($validated['persona_entry_id']);
        $personas->link($knowledge, $other);

        return redirect()
            ->route('console.knowledge.edit', $knowledge)
            ->with('status', 'Persona linked.');
    }

    public function destroyPersona(KnowledgeEntry $knowledge, KnowledgeEntry $persona, PersonaLinks $personas): RedirectResponse
    {
        $personas->unlink($knowledge, $persona);

        return redirect()
            ->route('console.knowledge.edit', $knowledge)
            ->with('status', 'Persona link removed.');
    }

    public function storeInstructionCopy(Request $request, KnowledgeEntry $knowledge): RedirectResponse
    {
        $validated = $request->validate([
            'instruction_copy' => ['nullable', 'string', 'max:20000'],
        ]);

        $knowledge->instruction_copy = $validated['instruction_copy'] ?: null;
        $knowledge->save();

        if (User::query()->where('operator_entry_id', $knowledge->id)->where('is_owner', true)->exists()) {
            User::query()->where('is_owner', true)->update([
                'operator_persona' => $knowledge->instruction_copy,
            ]);
        }

        return redirect()
            ->route('console.knowledge.edit', $knowledge)
            ->with('status', 'Instruction copy saved.');
    }

    private function save(Request $request, ?KnowledgeEntry $entry, KnowledgeEntryWriter $writer): KnowledgeEntry
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', 'string', Rule::in(KnowledgeEntry::TYPES)],
            'aliases' => ['nullable', 'string', 'max:4200'],
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
            ->when($entry, fn ($q) => $q->whereKeyNot($entry->id))
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
            entryName: $entry?->name ?? $name,
            entryId: $entry?->id,
            type: $validated['type'],
            aliases: $request->exists('aliases') ? self::lines($validated['aliases'] ?? null) : null,
            related: self::lines($validated['related'] ?? null),
            fields: [
                'summary' => $validated['summary'] ?? '',
                'state' => $validated['state'] ?? '',
                'source' => $validated['source'] ?? '',
            ],
            expectedVersion: $request->filled('expected_version') ? $request->integer('expected_version') : null,
            verified: $request->boolean('verified'),
            allowRename: $entry !== null,
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

        return $result->entry;
    }

    /**
     * The console edits alias/related lists as one-per-line text —
     * identical to the MCP tool's array contract after splitting.
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
}
