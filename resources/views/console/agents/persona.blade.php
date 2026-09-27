<x-console-layout :title="'Persona — '.$agent->name" :agent="$agent">
    <x-console-panel>
        <x-page-header eyebrow="PERSONA &amp; MEMORY" title="{{ $agent->name }}">
        </x-page-header>
        <p class="mt-3 text-sm text-steel">
            The persona is the agent's self-authored "who I am" text; the memory log is its append-only
            journal. Both are what <code>read_persona</code> returns and <code>update_persona</code>
            maintains — you can edit the persona, append an entry, or prune one here.
        </p>

        <form method="POST" action="{{ route('console.agents.persona.update', $agent) }}" class="mt-6 space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label for="persona" class="block font-mono text-xs text-steel">persona</label>
                <textarea id="persona" name="persona" rows="8" maxlength="20000"
                    placeholder="who this imp is, how it talks, what it cares about"
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">{{ old('persona', $agent->persona) }}</textarea>
                @error('persona') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="remember" class="block font-mono text-xs text-steel">append a memory</label>
                <input id="remember" name="remember" type="text" maxlength="2000"
                    value="{{ old('remember') }}"
                    placeholder="one line this imp should remember"
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('remember') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <button type="submit"
                class="border border-amber bg-amber px-4 py-2 font-mono text-sm font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">
                save
            </button>
            <a href="{{ route('console.agents.show', $agent) }}" class="ml-3 font-mono text-xs text-steel underline decoration-border underline-offset-4">cancel</a>
        </form>

        <div class="mt-8 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">MEMORY LOG</p>
            @if ($agent->memories)
                <ul class="mt-3 space-y-1 font-mono text-xs">
                    @foreach ($agent->memories as $index => $memory)
                        <li class="flex items-start justify-between gap-3 border border-border/50 bg-void/60 px-3 py-1.5" style="border-radius: var(--radius-console)">
                            <span class="min-w-0">
                                <span class="text-steel">{{ $memory['at'] ?? '' }}</span>
                                <span class="break-words text-cream">{{ $memory['text'] ?? '' }}</span>
                            </span>
                            <x-confirm-form action="{{ route('console.agents.memories.destroy', [$agent, $index]) }}" method="DELETE" confirm="Delete this memory?">
                                <input type="hidden" name="expect" value="{{ md5(json_encode($memory)) }}">
                                <button type="submit" class="shrink-0 font-mono text-[10px] tracking-[0.12em] text-red-400 hover:text-red-300">REMOVE</button>
                            </x-confirm-form>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 font-mono text-xs text-steel">no memories yet — the imp appends them itself via update_persona.</p>
            @endif
        </div>
    </x-console-panel>
</x-console-layout>
