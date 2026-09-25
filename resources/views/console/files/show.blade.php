<x-console-layout :title="'Files — '.$agent->name">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">FILE TREE — {{ strtoupper($agent->name) }}</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">{{ $agent->name }}</h1>
            </div>
            <a href="{{ route('console.files.edit', ['agent' => $agent, 'path' => '']) }}"
                class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                style="border-radius: var(--radius-console)">new file</a>
        </div>

        @if ($paths->isEmpty())
            <p class="mt-6 text-sm text-steel">The tree is empty. The agent will write here over MCP, or you can seed a file now.</p>
        @else
            <table class="mt-6 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">path</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($paths as $path)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream">{{ $path }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.files.edit', ['agent' => $agent, 'path' => $path]) }}" class="text-amber underline decoration-border underline-offset-4">open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>
</x-console-layout>
