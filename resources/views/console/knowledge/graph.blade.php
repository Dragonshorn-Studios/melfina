<x-console-layout :title="'Knowledge graph'">
    <x-console-panel class="p-0">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-6 py-4">
            <x-page-header eyebrow="KNOWLEDGE" title="Constellation">
                <x-slot:subtitle>Typed links. Confidence on every edge. Owner can lock one permanent.</x-slot:subtitle>
            </x-page-header>
            <div class="flex gap-2 font-mono text-xs items-center">
                <a href="{{ route('console.knowledge.index') }}" class="border border-transparent px-3 py-1.5 text-steel hover:border-border hover:text-cream" style="border-radius: var(--radius-console)">entries</a>
                <span class="border border-amber bg-void px-3 py-1.5 text-amber" style="border-radius: var(--radius-console)">graph</span>
                <button id="kg-refresh" class="border border-transparent px-3 py-1.5 text-steel hover:border-border hover:text-cream" style="border-radius: var(--radius-console)" title="Reload the graph from the server">refresh</button>
            </div>
        </div>

        @if (session('status'))
            <p class="border-b border-border px-6 py-2 font-mono text-xs text-amber">{{ session('status') }}</p>
        @endif
        @error('link')
            <p class="border-b border-border px-6 py-2 font-mono text-xs text-danger">{{ $message }}</p>
        @enderror

        <div class="grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
            <div id="knowledge-graph" class="kg-stage relative min-h-[32rem] lg:min-h-[36rem] lg:h-[min(72vh,40rem)]"
                data-focus="{{ $focusId ?? '' }}"
                data-link="{{ request('link') }}">
                <div class="absolute top-3 left-4 z-10">
                    <input id="kg-search" type="text" list="kg-search-results" autocomplete="off" placeholder="Search nodes..."
                        class="border border-border/50 bg-void/80 px-2 py-1 font-mono text-xs text-cream placeholder:text-steel/60 rounded"
                        style="border-radius: var(--radius-console)">
                    <datalist id="kg-search-results" class="hidden"></datalist>
                </div>
                <svg class="kg-svg absolute inset-0 h-full w-full" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Knowledge constellation"></svg>
                @if ($snapshot['nodes'] === [])
                    <p class="pointer-events-none absolute inset-0 flex items-center justify-center font-mono text-xs text-steel">No entities yet — create one here, or wait for an agent to upsert.</p>
                @endif
                <p class="pointer-events-none absolute bottom-3 left-4 font-mono text-[10px] tracking-[0.16em] text-steel/70">NAV · AMBER = PERMANENT OR FOCUSED · STEEL = INFERRED · DASHED = LOW CONFIDENCE · RED DASHED = DISPUTED</p>
            </div>

            <aside class="border-t border-border bg-void/40 lg:border-t-0 lg:border-l">
                <div id="kg-inspect-empty" class="space-y-3 p-4">
                    <p class="font-mono text-[10px] tracking-[0.16em] text-steel">INSPECT</p>
                    <p class="text-sm text-steel">Click a node to focus it. Click an edge to confirm, fix, retract, or mark it permanent. Create only when the graph is missing a noun.</p>
                    <p class="font-mono text-xs text-steel/60 mt-2">Press <kbd class="px-1 border border-border/30">F</kbd> to focus, <kbd class="px-1 border border-border/30">+</kbd>/<kbd class="px-1 border border-border/30">-</kbd> to zoom, <kbd class="px-1 border border-border/30">0</kbd> to reset view, <kbd class="px-1 border border-border/30">Esc</kbd> to clear the selection, <kbd class="px-1 border border-border/30">Delete</kbd> to remove what is selected.</p>
                </div>

                <div id="kg-inspect-node" class="hidden space-y-4 p-4">
                    <p class="font-mono text-[10px] tracking-[0.16em] text-steel">ENTITY</p>
                    <p id="kg-node-name" class="font-semibold text-cream"></p>
                    <p id="kg-node-meta" class="font-mono text-xs text-steel"></p>
                    <p id="kg-node-summary" class="text-sm text-steel"></p>
                    <a id="kg-node-edit" href="#" class="font-mono text-xs text-amber underline decoration-border underline-offset-4">edit wiki</a>
                </div>

                <div id="kg-inspect-link" class="hidden space-y-4 p-4">
                    <p class="font-mono text-[10px] tracking-[0.16em] text-steel">LINK</p>
                    <p id="kg-link-label" class="font-mono text-xs text-cream"></p>
                    <p id="kg-link-meta" class="font-mono text-xs text-steel"></p>

                    <form id="kg-fix-form" method="POST" action="{{ route('api.knowledge.links.update', ['link' => 0]) }}" class="space-y-2" data-ajax="true"
                        @csrf
                        @method('PUT')
                        <label class="block font-mono text-[10px] tracking-[0.12em] text-steel">
                            from
                            <input id="kg-fix-from" type="text" name="from" maxlength="200" required
                                class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                        </label>
                        <label class="block font-mono text-[10px] tracking-[0.12em] text-steel">
                            predicate
                            <select id="kg-fix-predicate" name="predicate"
                                class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                                @foreach ($predicates as $p)
                                    <option value="{{ $p }}">{{ $p }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block font-mono text-[10px] tracking-[0.12em] text-steel">
                            to
                            <input id="kg-fix-to" type="text" name="to" maxlength="200" required
                                class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                        </label>
                        <button type="submit" class="border border-border px-3 py-1 font-mono text-xs text-cream hover:border-amber hover:text-amber" style="border-radius: var(--radius-console)">fix link</button>
                    </form>

                    <div class="flex flex-wrap gap-2">
                        <form id="kg-confirm-form" method="POST" action="{{ route('api.knowledge.links.confirm', ['link' => 0]) }}" data-ajax="true">
                            @csrf
                            <button type="submit" class="border border-amber bg-amber px-3 py-1 font-mono text-xs font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">confirm</button>
                        </form>
                        <form id="kg-permanent-form" method="POST" action="{{ route('api.knowledge.links.permanent', ['link' => 0]) }}" data-ajax="true">
                            @csrf
                            <input type="hidden" name="permanent" value="1">
                            <button type="submit" class="border border-amber px-3 py-1 font-mono text-xs text-amber hover:bg-void" style="border-radius: var(--radius-console)">mark permanent</button>
                        </form>
                        <form id="kg-unpermanent-form" method="POST" action="{{ route('api.knowledge.links.permanent', ['link' => 0]) }}" class="hidden" data-ajax="true">
                            @csrf
                            <input type="hidden" name="permanent" value="0">
                            <button type="submit" class="border border-border px-3 py-1 font-mono text-xs text-steel hover:border-amber hover:text-amber" style="border-radius: var(--radius-console)">lift lock</button>
                        </form>
                        {{-- The viewer confirms this one itself. The shared
                             gate only picks up forms carrying data-confirm,
                             and it submits them natively — which would
                             bypass the in-page fetch and land the browser
                             on a JSON body. --}}
                        <form id="kg-retract-form" method="POST" action="{{ route('api.knowledge.links.retract', ['link' => 0]) }}" data-ajax="true">
                            @csrf
                            <button type="submit" class="font-mono text-xs text-danger hover:text-cream">retract</button>
                        </form>
                    </div>
                </div>

                <div class="border-t border-border p-4">
                    <p class="font-mono text-[10px] tracking-[0.16em] text-steel">NEW LINK</p>
                    <form id="kg-new-link-form" method="POST" action="{{ route('api.knowledge.links.store') }}" class="mt-3 space-y-2" data-ajax="true"
                        @csrf
                        <label class="block font-mono text-[10px] text-steel">
                            from
                            <input id="kg-new-from" type="text" name="from" list="kg-names" required maxlength="200"
                                class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                        </label>
                        <label class="block font-mono text-[10px] text-steel">
                            predicate
                            <select name="predicate" required
                                class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                                @foreach ($predicates as $p)
                                    <option value="{{ $p }}" @selected($p === 'related_to')>{{ $p }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block font-mono text-[10px] text-steel">
                            to
                            <input type="text" name="to" list="kg-names" required maxlength="200"
                                class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                        </label>
                        <label class="block font-mono text-[10px] text-steel">
                            note
                            <input type="text" name="note" maxlength="500"
                                class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                        </label>
                        <label class="flex items-center gap-2 font-mono text-[10px] text-steel">
                            <input type="checkbox" name="create_missing" value="1" class="accent-amber">
                            create missing ends as concepts
                        </label>
                        <button type="submit" class="border border-amber bg-amber px-3 py-1 font-mono text-xs font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">write link</button>
                    </form>
                </div>

                <div class="border-t border-border p-4">
                    <p class="font-mono text-[10px] tracking-[0.16em] text-steel">NEW ENTITY</p>
                    <form id="kg-new-entity-form" method="POST" action="{{ route('api.knowledge.entries.store') }}" class="mt-3 space-y-2" data-ajax="true"
                        @csrf
                        <label class="block font-mono text-[10px] text-steel">
                            name
                            <input type="text" name="name" required maxlength="200"
                                class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                        </label>
                        <label class="block font-mono text-[10px] text-steel">
                            type
                            <select name="type" required
                                class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                                @foreach ($types as $t)
                                    <option value="{{ $t }}">{{ $t }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block font-mono text-[10px] text-steel">
                            summary
                            <input type="text" name="summary" maxlength="500"
                                class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                        </label>
                        <button type="submit" class="border border-border px-3 py-1 font-mono text-xs text-cream hover:border-amber hover:text-amber" style="border-radius: var(--radius-console)">create entity</button>
                    </form>
                </div>
            </aside>
        </div>

        <datalist id="kg-names">
            @foreach ($entries as $entry)
                <option value="{{ $entry->name }}"></option>
            @endforeach
        </datalist>

        <script type="application/json" id="kg-data">@json($snapshot)</script>
    </x-console-panel>
</x-console-layout>
