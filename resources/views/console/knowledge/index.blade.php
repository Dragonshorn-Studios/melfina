<x-console-layout :title="'Knowledge'">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">KNOWLEDGE</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">Canonical entries, one shared base</h1>
            </div>
            <a href="{{ route('console.knowledge.create') }}"
                class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                style="border-radius: var(--radius-console)">new entry</a>
        </div>

        <form method="GET" action="{{ route('console.knowledge.index') }}" class="mt-4 flex gap-2">
            <input type="text" name="q" value="{{ $q }}" placeholder="search names, aliases, summaries…" maxlength="200"
                class="min-w-0 flex-1 border border-border bg-void/60 px-3 py-1.5 font-mono text-xs text-cream placeholder:text-steel/60"
                style="border-radius: var(--radius-console)">
            <select name="type"
                class="border border-border bg-void px-2 py-1.5 font-mono text-xs text-cream"
                style="border-radius: var(--radius-console)">
                <option value="">all types</option>
                @foreach ($types as $t)
                    <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
                @endforeach
            </select>
            <button type="submit"
                class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                style="border-radius: var(--radius-console)">search</button>
        </form>

        @if (session('status'))
            <p class="mt-3 font-mono text-xs text-amber">{{ session('status') }}</p>
        @endif

        @if ($entries->isEmpty())
            <p class="mt-6 text-sm text-steel">
                {{ $q !== '' || $type !== '' ? 'No entries match — every query token must hit a name, alias, summary, state, or related name.' : 'No entries yet — agents write them via knowledge_upsert, or create one yourself.' }}
            </p>
        @else
            <table class="mt-4 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">name</th>
                        <th class="py-2 pr-4">type</th>
                        <th class="py-2 pr-4">summary</th>
                        <th class="py-2 pr-4">v</th>
                        <th class="py-2 pr-4">last write</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream">{{ $entry->name }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $entry->type }}</td>
                            <td class="max-w-56 truncate py-2 pr-4 text-steel" title="{{ $entry->summary }}">{{ $entry->summary }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $entry->version }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $entry->updated_by }} · {{ $entry->updated_at->diffForHumans(short: true) }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.knowledge.edit', $entry) }}" class="text-amber underline decoration-border underline-offset-4">edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>
</x-console-layout>
