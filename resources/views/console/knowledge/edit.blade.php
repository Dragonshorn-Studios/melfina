<x-console-layout :title="$entry ? $entry->name : 'New knowledge entry'">
    <x-console-panel>
        <x-page-header eyebrow="KNOWLEDGE" title="{{ $entry ? $entry->name : 'New entry' }}">
            @if ($entry)
                <x-slot:subtitle>v{{ $entry->version }} · last write by {{ $entry->updated_by }} {{ $entry->updated_at->diffForHumans(short: true) }}</x-slot:subtitle>
                <x-slot:actions>
                    <a href="{{ route('console.knowledge.graph', ['focus' => $entry->id]) }}"
                        class="border border-border px-3 py-1.5 font-mono text-xs text-cream hover:border-amber hover:text-amber"
                        style="border-radius: var(--radius-console)">graph</a>
                </x-slot:actions>
            @endif
        </x-page-header>

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

            <label class="block font-mono text-xs text-steel">
                related — canonical names, one per line
                <textarea name="related" rows="3" maxlength="4200"
                    class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">{{ old('related', implode("\n", $entry?->related ?? [])) }}</textarea>
                @error('related')<span class="text-danger">{{ $message }}</span>@enderror
            </label>

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
            @error('delete')
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
                <p class="font-mono text-xs tracking-[0.16em] text-steel">IDENTITY</p>
                <p class="mt-1 font-mono text-xs text-steel">id {{ $entry->id }}
                    @if ($isOperator)
                        · installation operator
                    @endif
                </p>

                @if ($entry->type === 'person' && ! $isOperator)
                    <form method="POST" action="{{ route('console.knowledge.operator.store', $entry) }}" class="mt-3">
                        @csrf
                        <button type="submit"
                            class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                            style="border-radius: var(--radius-console)">bind as operator person</button>
                    </form>
                @endif

                <p class="mt-4 font-mono text-xs tracking-[0.16em] text-steel">ALIASES</p>
                <p class="mt-1 font-mono text-xs text-steel">
                    Another name for this same entity. Lookup returns this entry. Projects, places, and people all use this list.
                </p>
                @if (($identifiers ?? collect())->isNotEmpty())
                    <ul class="mt-3 space-y-1 font-mono text-xs">
                        @foreach ($identifiers as $identifier)
                            <li class="flex items-center justify-between gap-3 border border-border/50 bg-void/60 px-3 py-1.5" style="border-radius: var(--radius-console)">
                                <span class="text-cream">{{ $identifier->kind->value }} · {{ $identifier->value }}</span>
                                <x-confirm-form action="{{ route('console.knowledge.identifiers.destroy', [$entry, $identifier]) }}" method="DELETE" confirm="Remove alias '{{ $identifier->value }}'? Lookup will stop matching it.">
                                    <button type="submit" class="text-steel underline decoration-border underline-offset-4 hover:text-danger">remove</button>
                                </x-confirm-form>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-3 font-mono text-xs text-steel">No aliases yet.</p>
                @endif
                <form method="POST" action="{{ route('console.knowledge.identifiers.store', $entry) }}" class="mt-3 flex flex-wrap items-start gap-2">
                    @csrf
                    <input type="hidden" name="kind" value="alias">
                    <input type="text" name="value" placeholder="another name" maxlength="200"
                        class="min-w-0 flex-1 border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                        style="border-radius: var(--radius-console)">
                    <button type="submit"
                        class="border border-border bg-void px-3 py-1 font-mono text-xs text-cream hover:border-amber"
                        style="border-radius: var(--radius-console)">add alias</button>
                </form>
                @error('value')<p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p>@enderror

                @if ($entry->type === 'person')
                    <p class="mt-6 font-mono text-xs tracking-[0.16em] text-steel">PERSONAS</p>
                    <p class="mt-1 font-mono text-xs text-steel">
                        Other person entries for the same living being. Lookup keeps each name and reports that they are equal.
                    </p>
                    @if (($personaLinks ?? collect())->isNotEmpty())
                        <ul class="mt-3 space-y-1 font-mono text-xs">
                            @foreach ($personaLinks as $persona)
                                <li class="flex items-center justify-between gap-3 border border-border/50 bg-void/60 px-3 py-1.5" style="border-radius: var(--radius-console)">
                                    <a class="text-cream underline decoration-border underline-offset-4" href="{{ route('console.knowledge.edit', $persona) }}">{{ $persona->name }}</a>
                                    <x-confirm-form action="{{ route('console.knowledge.personas.destroy', [$entry, $persona]) }}" method="DELETE" confirm="Unlink persona '{{ $persona->name }}'?">
                                        <button type="submit" class="text-steel underline decoration-border underline-offset-4 hover:text-danger">unlink</button>
                                    </x-confirm-form>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-3 font-mono text-xs text-steel">No persona links yet.</p>
                    @endif
                    @if (($people ?? collect())->isNotEmpty())
                        <form method="POST" action="{{ route('console.knowledge.personas.store', $entry) }}" class="mt-3 flex flex-wrap gap-2">
                            @csrf
                            <select name="persona_entry_id"
                                class="min-w-0 flex-1 border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                                style="border-radius: var(--radius-console)">
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit"
                                class="border border-border bg-void px-3 py-1 font-mono text-xs text-cream hover:border-amber"
                                style="border-radius: var(--radius-console)">link persona</button>
                        </form>
                    @endif
                    @error('persona')<p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p>@enderror

                    <p class="mt-6 font-mono text-xs tracking-[0.16em] text-steel">INSTRUCTION COPY</p>
                    <p class="mt-1 font-mono text-xs text-steel">
                        Paragraph agents read for this person when it is their main person. It does not change the agent’s own instruction text.
                    </p>
                    <form method="POST" action="{{ route('console.knowledge.instruction-copy.store', $entry) }}" class="mt-3">
                        @csrf
                        @method('PUT')
                        <textarea name="instruction_copy" rows="3"
                            class="w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                            style="border-radius: var(--radius-console)">{{ old('instruction_copy', $entry->instruction_copy) }}</textarea>
                        @error('instruction_copy')<p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p>@enderror
                        <button type="submit"
                            class="mt-2 border border-border bg-void px-3 py-1 font-mono text-xs text-cream hover:border-amber"
                            style="border-radius: var(--radius-console)">save copy</button>
                    </form>
                @endif
            </div>

            <div class="mt-8 border-t border-border pt-4">
                <div class="flex items-center justify-between">
                    <p class="font-mono text-xs tracking-[0.16em] text-steel">VERSION HISTORY</p>
                    <x-confirm-form action="{{ route('console.knowledge.destroy', $entry) }}" method="DELETE" confirm="Delete '{{ $entry->name }}'? Its links and observations go with it.">
                        <button type="submit" class="font-mono text-[10px] tracking-[0.12em] text-red-400 hover:text-red-300">DELETE ENTRY</button>
                    </x-confirm-form>
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
    </x-console-panel>
</x-console-layout>
