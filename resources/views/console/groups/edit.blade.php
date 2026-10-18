<x-console-layout :title="$group ? $group->name : 'New group'">
    <x-console-panel>
        <x-page-header eyebrow="GROUP" title="{{ $group ? $group->name : 'New group' }}">
            <x-slot:subtitle>The preamble is prepended to each member’s instruction layers. It does not change their instruction text. The shared main person applies only when the agent has not chosen one, and only when every group that sets a main person agrees.</x-slot:subtitle>
        </x-page-header>

        @if (session('status'))
            <p class="mt-3 font-mono text-xs text-amber">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ $group ? route('console.groups.update', $group) : route('console.groups.store') }}" class="mt-6 space-y-4">
            @csrf
            @if ($group)
                @method('PUT')
            @endif

            <label class="block font-mono text-xs text-steel">
                name
                <input type="text" name="name" value="{{ old('name', $group?->name) }}" required maxlength="80"
                    class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">
                @error('name')<span class="text-danger">{{ $message }}</span>@enderror
            </label>

            <label class="block font-mono text-xs text-steel">
                preamble — shared instruction layer
                <textarea name="preamble" rows="4" maxlength="20000"
                    class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">{{ old('preamble', $group?->preamble) }}</textarea>
                @error('preamble')<span class="text-danger">{{ $message }}</span>@enderror
            </label>

            <label class="block font-mono text-xs text-steel">
                shared main person
                <select name="main_person_entry_id"
                    class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">
                    <option value="">none</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected((string) old('main_person_entry_id', $group?->main_person_entry_id) === (string) $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
                @error('main_person_entry_id')<span class="text-danger">{{ $message }}</span>@enderror
            </label>

            <div>
                <p class="font-mono text-xs text-steel">members</p>
                @if ($agents->isEmpty())
                    <p class="mt-2 text-sm text-steel">No agents yet.</p>
                @else
                    <div class="mt-2 space-y-1 font-mono text-xs text-cream">
                        @foreach ($agents as $agent)
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="agents[]" value="{{ $agent->id }}" class="accent-amber"
                                    @checked(collect(old('agents', $members))->contains($agent->id))>
                                {{ $agent->name }}
                            </label>
                        @endforeach
                    </div>
                @endif
                @error('agents')<p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                    class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                    style="border-radius: var(--radius-console)">{{ $group ? 'save' : 'create' }}</button>
                <a href="{{ route('console.groups.index') }}" class="font-mono text-xs text-steel underline decoration-border underline-offset-4">back</a>
            </div>
        </form>

        @if ($group)
            <div class="mt-8 border-t border-border pt-4">
                <x-confirm-form action="{{ route('console.groups.destroy', $group) }}" method="DELETE" confirm="Delete group '{{ $group->name }}'? Members keep their own main person. Grants on this group are removed.">
                    <button type="submit" class="font-mono text-[10px] tracking-[0.12em] text-red-400 hover:text-red-300">DELETE GROUP</button>
                </x-confirm-form>
            </div>
        @endif
    </x-console-panel>
</x-console-layout>
