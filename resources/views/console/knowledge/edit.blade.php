<x-console-layout :title="$entry ? $entry->name : 'New knowledge entry'">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">KNOWLEDGE</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">{{ $entry ? $entry->name : 'New entry' }}</h1>
        @if ($entry)
            <p class="mt-1 font-mono text-xs text-steel">v{{ $entry->version }} · last write by {{ $entry->updated_by }} {{ $entry->updated_at->diffForHumans(short: true) }}</p>
        @endif

        @if (session('status'))
            <p class="mt-3 font-mono text-xs text-amber">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ $entry ? route('console.knowledge.update', $entry) : route('console.knowledge.store') }}" class="mt-6 space-y-4">
            @csrf
            @if ($entry)
                @method('PUT')
                {{-- echoed so a stale edit fails loudly instead of clobbering an agent's newer write --}}
                <input type="hidden" name="expected_version" value="{{ $entry->version }}">
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block font-mono text-xs text-steel">
                    name
                    <input type="text" name="name" value="{{ old('name', $entry?->name) }}" required maxlength="200"
                        class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                        style="border-radius: var(--radius-console)">
                    @error('name')<span class="text-danger">{{ $message }}</span>@enderror
                </label>
                <label class="block font-mono text-xs text-steel">
                    type
                    <select name="type" required
                        class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                        style="border-radius: var(--radius-console)">
                        @foreach ($types as $t)
                            <option value="{{ $t }}" @selected(old('type', $entry?->type) === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                    @error('type')<span class="text-danger">{{ $message }}</span>@enderror
                </label>
            </div>

            <label class="block font-mono text-xs text-steel">
                summary — one line
                <input type="text" name="summary" value="{{ old('summary', $entry?->summary) }}" maxlength="500"
                    class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">
                @error('summary')<span class="text-danger">{{ $message }}</span>@enderror
            </label>

            <label class="block font-mono text-xs text-steel">
                state — current state and important constraints
                <textarea name="state" rows="6" maxlength="20000"
                    class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">{{ old('state', $entry?->state) }}</textarea>
                @error('state')<span class="text-danger">{{ $message }}</span>@enderror
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block font-mono text-xs text-steel">
                    aliases — one per line
                    <textarea name="aliases" rows="3" maxlength="4200"
                        class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                        style="border-radius: var(--radius-console)">{{ old('aliases', implode("\n", $entry?->aliases ?? [])) }}</textarea>
                    @error('aliases')<span class="text-danger">{{ $message }}</span>@enderror
                </label>
                <label class="block font-mono text-xs text-steel">
                    related — canonical names, one per line
                    <textarea name="related" rows="3" maxlength="4200"
                        class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                        style="border-radius: var(--radius-console)">{{ old('related', implode("\n", $entry?->related ?? [])) }}</textarea>
                    @error('related')<span class="text-danger">{{ $message }}</span>@enderror
                </label>
            </div>

            <label class="block font-mono text-xs text-steel">
                source — where this knowledge came from
                <input type="text" name="source" value="{{ old('source', $entry?->source) }}" maxlength="500"
                    class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">
                @error('source')<span class="text-danger">{{ $message }}</span>@enderror
            </label>

            <label class="flex items-center gap-2 font-mono text-xs text-steel">
                <input type="checkbox" name="verified" value="1" class="accent-amber" @checked(old('verified'))>
                verified — stamp verified_at with now
            </label>

            @error('expected_version')
                <p class="font-mono text-xs text-danger">{{ $message }}</p>
            @enderror

            <div class="flex items-center gap-3">
                <button type="submit"
                    class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                    style="border-radius: var(--radius-console)">{{ $entry ? 'save' : 'create' }}</button>
                <a href="{{ route('console.knowledge.index') }}" class="font-mono text-xs text-steel underline decoration-border underline-offset-4">back</a>
            </div>
        </form>

        @if ($entry)
            <div class="mt-8 border-t border-border pt-4">
                <div class="flex items-center justify-between">
                    <p class="font-mono text-xs tracking-[0.16em] text-steel">VERSION HISTORY</p>
                    <form method="POST" action="{{ route('console.knowledge.destroy', $entry) }}"
                        onsubmit="return window.__melfinaConfirm || confirm(this.dataset.confirm)" data-confirm="Delete '{{ $entry->name }}'? Its whole version history goes with it.">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="font-mono text-[10px] tracking-[0.12em] text-red-400 hover:text-red-300">DELETE ENTRY</button>
                    </form>
                </div>
                @if ($versions->isEmpty())
                    <p class="mt-2 font-mono text-xs text-steel">no recorded versions.</p>
                @else
                    <ul class="mt-3 space-y-1 font-mono text-xs">
                        @foreach ($versions as $v)
                            <li class="border border-border/50 bg-void/60 px-3 py-1.5" style="border-radius: var(--radius-console)">
                                <details>
                                    <summary class="cursor-pointer text-steel hover:text-cream">
                                        v{{ $v->version }} · {{ $v->author }} · {{ $v->created_at->toDateTimeString() }}
                                    </summary>
                                    <pre class="mt-2 whitespace-pre-wrap break-words text-cream">{{ json_encode($v->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </main>
</x-console-layout>
