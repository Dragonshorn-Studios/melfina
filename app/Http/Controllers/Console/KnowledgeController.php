<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $tokens = collect(preg_split('/\s+/', Str::lower(trim($request->string('q')->toString()))))
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

    public function store(Request $request): RedirectResponse
    {
        $entry = $this->save($request, null);

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
        ]);
    }

    public function update(Request $request, KnowledgeEntry $knowledge): RedirectResponse
    {
        $entry = $this->save($request, $knowledge);

        return redirect()
            ->route('console.knowledge.edit', $entry)
            ->with('status', "Entry '{$entry->name}' saved — version {$entry->version}.");
    }

    /**
     * Delete an entry outright; its version history cascades with it.
     */
    public function destroy(KnowledgeEntry $knowledge): RedirectResponse
    {
        $name = $knowledge->name;
        $knowledge->delete();

        return redirect()
            ->route('console.knowledge.index')
            ->with('status', "Entry '{$name}' deleted.");
    }

    /**
     * The owner-side equivalent of knowledge_upsert: same field limits,
     * same versioned write, author stamped 'owner'. `expected_version`
     * (echoed by the edit form) fails instead of clobbering a change an
     * agent just wrote — same contract as the MCP tool.
     */
    private function save(Request $request, ?KnowledgeEntry $entry): KnowledgeEntry
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
        $nameLower = Str::lower($name);

        // Canonical names are unique case-insensitively (name_lower is the
        // unique key) — point at the existing entry rather than 500ing on
        // the constraint.
        $collision = KnowledgeEntry::query()
            ->where('name_lower', $nameLower)
            ->when($entry, fn ($q) => $q->whereKeyNot($entry->id))
            ->first();
        if ($collision !== null) {
            throw ValidationException::withMessages([
                'name' => "'{$collision->name}' already uses this name.",
            ]);
        }

        if ($entry !== null
            && $request->has('expected_version')
            && $entry->version !== $request->integer('expected_version')) {
            throw ValidationException::withMessages([
                'expected_version' => "The entry changed since this page loaded (now v{$entry->version}) — reload and merge your edit.",
            ]);
        }

        return DB::transaction(function () use ($validated, $entry, $name, $nameLower, $request): KnowledgeEntry {
            $entry ??= new KnowledgeEntry;

            $entry->name = $name;
            $entry->name_lower = $nameLower;
            $entry->type = $validated['type'];
            $entry->summary = $validated['summary'] ?? '';
            $entry->state = $validated['state'] ?? '';
            $entry->source = $validated['source'] ?? '';
            $entry->aliases = self::lines($validated['aliases'] ?? null);
            $entry->related = self::lines($validated['related'] ?? null);

            if ($request->boolean('verified')) {
                $entry->verified_at = now();
            }

            $entry->updated_by = 'owner';
            $entry->version = ($entry->version ?? 0) + 1;
            $entry->save();

            $entry->recordVersion('owner');

            return $entry;
        });
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
