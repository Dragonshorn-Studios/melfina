<x-console-layout :title="'New agent'">
    <x-console-panel>
        <x-page-header eyebrow="NEW AGENT" title="Mint a key and a brain">
            <x-slot:subtitle>The Bearer/MCP key is shown exactly once after creation.</x-slot:subtitle>
        </x-page-header>

        <form method="POST" action="{{ route('console.agents.store') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="name" class="block font-mono text-xs text-steel">name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('name') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <button type="submit"
                class="border border-amber bg-amber px-4 py-2 font-mono text-sm font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">
                create agent
            </button>
            <a href="{{ route('console.agents.index') }}" class="ml-3 font-mono text-xs text-steel underline decoration-border underline-offset-4">cancel</a>
        </form>
    </x-console-panel>
</x-console-layout>
