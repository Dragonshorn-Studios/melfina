<x-console-layout :title="'New shared space'">
    <x-console-panel>
        <x-page-header eyebrow="NEW SHARED SPACE" title="A shared folder">
            <x-slot:subtitle>Agents only see it once you grant read or write on the ACL.</x-slot:subtitle>
        </x-page-header>

        <form method="POST" action="{{ route('console.shared.store') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="name" class="block font-mono text-xs text-steel">name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('name') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <button type="submit"
                class="border border-amber bg-amber px-4 py-2 font-mono text-sm font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">
                create space
            </button>
            <a href="{{ route('console.shared.index') }}" class="ml-3 font-mono text-xs text-steel underline decoration-border underline-offset-4">cancel</a>
        </form>
    </x-console-panel>
</x-console-layout>
