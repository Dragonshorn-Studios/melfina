<x-console-layout :title="'Edit '.$agent->name" :agent="$agent">
    <x-console-panel>
        <x-page-header eyebrow="EDIT AGENT" title="{{ $agent->name }}">
        </x-page-header>

        <form method="POST" action="{{ route('console.agents.update', $agent) }}" class="mt-6 space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label for="name" class="block font-mono text-xs text-steel">name</label>
                <input id="name" name="name" type="text" value="{{ old('name', $agent->name) }}" required
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('name') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="description" class="block font-mono text-xs text-steel">description</label>
                <input id="description" name="description" type="text" value="{{ old('description', $agent->description) }}" maxlength="500"
                    placeholder="one line — who is this imp? (visible to other agents)"
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('description') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="status" class="block font-mono text-xs text-steel">status</label>
                <select id="status" name="status" required
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                    <option value="active" @selected(old('status', $agent->status) === 'active')>active</option>
                    <option value="disabled" @selected(old('status', $agent->status) === 'disabled')>disabled</option>
                </select>
                @error('status') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <button type="submit"
                class="border border-amber bg-amber px-4 py-2 font-mono text-sm font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">
                save
            </button>
            <a href="{{ route('console.agents.show', $agent) }}" class="ml-3 font-mono text-xs text-steel underline decoration-border underline-offset-4">cancel</a>
        </form>
    </x-console-panel>
</x-console-layout>
