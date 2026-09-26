<x-console-layout :title="$space->name.' — '.$file->path">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">{{ strtoupper($space->name) }}</p>
                <h1 class="mt-2 break-all font-mono text-xl font-semibold tracking-tight text-cream">{{ $file->path }}</h1>
                <p class="mt-2 text-sm text-steel">updated {{ $file->updated_at->toDateTimeString() }}</p>
            </div>
            <a href="{{ route('console.shared.show', $space) }}"
                class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                style="border-radius: var(--radius-console)">back</a>
        </div>

        <pre class="mt-6 overflow-x-auto whitespace-pre-wrap break-words border border-border bg-void/60 p-4 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $file->content }}</pre>
    </main>
</x-console-layout>
