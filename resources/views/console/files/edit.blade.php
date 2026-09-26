<x-console-layout :title="($path === '' ? 'New file' : $path).' — '.$agent->name" :agent="$agent">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">{{ $path === '' ? 'NEW FILE' : 'EDIT FILE' }} — {{ strtoupper($agent->name) }}</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">{{ $path === '' ? 'Write into the tree' : $path }}</h1>

        <form method="POST" action="{{ route('console.files.update', $agent) }}" class="mt-6 space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label for="path" class="block font-mono text-xs text-steel">path</label>
                <input id="path" name="path" type="text" value="{{ old('path', $path) }}" required
                    @if ($path !== '') readonly @endif
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber {{ $path !== '' ? 'opacity-60' : '' }}" style="border-radius: var(--radius-console)">
                @error('path') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="content" class="block font-mono text-xs text-steel">content</label>
                <textarea id="content" name="content" rows="14"
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">{{ old('content', $content) }}</textarea>
                @error('content') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center gap-3">
                <button type="submit"
                    class="border border-amber bg-amber px-4 py-2 font-mono text-sm font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">
                    save file
                </button>
                <a href="{{ route('console.files.show', $agent) }}" class="font-mono text-xs text-steel underline decoration-border underline-offset-4">back to tree</a>
            </div>
        </form>

        @if ($path !== '')
            <form method="POST" action="{{ route('console.files.destroy', $agent) }}" class="mt-8 border-t border-border pt-6"
                onsubmit="return window.__melfinaConfirm || confirm(this.dataset.confirm)" data-confirm="Delete {{ $path }}?">
                @csrf
                @method('DELETE')
                <input type="hidden" name="path" value="{{ $path }}">
                <button type="submit"
                    class="border border-danger px-3 py-1.5 font-mono text-xs text-danger hover:bg-danger hover:text-void" style="border-radius: var(--radius-console)">
                    delete file
                </button>
            </form>
        @endif
    </main>
</x-console-layout>
