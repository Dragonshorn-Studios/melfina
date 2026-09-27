<x-console-layout :title="'Files — '.$agent->name" :agent="$agent">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">FILE TREE — {{ strtoupper($agent->name) }}</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">{{ $agent->name }}</h1>
                <p class="mt-2 text-sm text-steel">{{ $fileCount }} {{ Str::plural('file', $fileCount) }} in the tree.</p>
            </div>
            <a href="{{ route('console.files.edit', ['agent' => $agent, 'path' => '']) }}"
                class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                style="border-radius: var(--radius-console)">new file</a>
        </div>

        @include('console.partials.dirtree', [
            'crumbs' => collect($breadcrumbs)->map(fn ($c) => [
                'name' => $c['name'],
                'url' => route('console.files.show', ['agent' => $agent, 'dir' => $c['dir']]),
            ]),
            'dirs' => collect($dirs)->map(fn ($d) => [
                'name' => $d,
                'url' => route('console.files.show', ['agent' => $agent, 'dir' => $dir.$d.'/']),
            ]),
        ])

        @if (empty($files) && empty($dirs))
            <p class="mt-6 text-sm text-steel">{{ $fileCount === 0 ? 'The tree is empty. The agent will write here over MCP, or you can seed a file now.' : 'Nothing at this level.' }}</p>
        @elseif (! empty($files))
            <table class="mt-4 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">file</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($files as $path)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream" title="{{ $path }}">{{ Str::after($path, $dir) }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.files.edit', ['agent' => $agent, 'path' => $path, 'dir' => $dir]) }}" class="text-amber underline decoration-border underline-offset-4">open</a>
                                <form method="POST" action="{{ route('console.files.destroy', $agent) }}" class="inline"
                                    onsubmit="return window.__melfinaConfirm || confirm(this.dataset.confirm)" data-confirm="Delete '{{ $path }}'? The agent sees a miss on its next read.">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="path" value="{{ $path }}">
                                    <input type="hidden" name="dir" value="{{ $dir }}">
                                    <button type="submit" class="ml-3 text-steel underline decoration-border underline-offset-4 hover:text-danger">delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @error('path')
            <p class="mt-2 font-mono text-xs text-danger">{{ $message }}</p>
        @enderror
    </main>
</x-console-layout>
