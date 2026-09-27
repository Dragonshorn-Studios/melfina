<x-console-layout :title="$space->name.' — '.$file->path">
    <x-console-panel>
        <x-page-header eyebrow="{{ strtoupper($space->name) }}" title="{{ $file->path }}">
            <x-slot:subtitle>updated {{ $file->updated_at->toDateTimeString() }}</x-slot:subtitle>
            <x-slot:actions>
<a href="{{ route('console.shared.show', ['shared' => $space, 'dir' => $dir]) }}"
                class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                style="border-radius: var(--radius-console)">back</a>
            </x-slot:actions>
        </x-page-header>

        <pre class="mt-6 overflow-x-auto whitespace-pre-wrap break-words border border-border bg-void/60 p-4 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $file->content }}</pre>
    </x-console-panel>
</x-console-layout>
