<x-console-layout :title="$file->path.' — '.$agent->name" :agent="$agent">
    <x-console-panel>
        <x-page-header eyebrow="{{ $file->isBinary() ? 'FILE' : 'TEXT FILE' }} — {{ strtoupper($agent->name) }}" title="{{ $file->path }}">
            <x-slot:subtitle>
                {{ $file->byteSize() }} B
                @if ($file->isBinary()) · {{ $file->mime }} @endif
                · updated {{ $file->updated_at->toDateTimeString() }}
            </x-slot:subtitle>
            <x-slot:actions>
<div class="flex gap-2">
                @if (! $file->isBinary())
                    <a href="{{ route('console.files.edit', ['agent' => $agent, 'path' => $file->path, 'dir' => $dir]) }}"
                        class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                        style="border-radius: var(--radius-console)">edit</a>
                @endif
                <a href="{{ route('console.files.raw', ['agent' => $agent, 'path' => $file->path]) }}"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">{{ $inlineSafe ? 'open raw' : 'download' }}</a>
                <a href="{{ route('console.files.show', ['agent' => $agent, 'dir' => $dir]) }}"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                    style="border-radius: var(--radius-console)">back</a>
            </div>
            </x-slot:actions>
        </x-page-header>

        @if ($file->isBinary() && $inlineSafe && str_starts_with($file->mime, 'image/'))
            <div class="mt-6 border border-border bg-void/60 p-4" style="border-radius: var(--radius-console)">
                <img src="{{ route('console.files.raw', ['agent' => $agent, 'path' => $file->path]) }}"
                    alt="{{ $file->path }}" class="mx-auto max-h-[70vh] max-w-full">
            </div>
        @elseif ($file->isBinary())
            <p class="mt-6 font-mono text-xs text-steel">
                Binary file — {{ $file->mime }}.
                @if (! $inlineSafe) Not previewable inline; use download. @endif
            </p>
        @else
            <pre class="mt-6 overflow-x-auto whitespace-pre-wrap break-words border border-border bg-void/60 p-4 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $file->content }}</pre>
        @endif
    </x-console-panel>
</x-console-layout>
