<x-console-layout :title="'Files'">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">FILES</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">One file tree per agent</h1>
        <p class="mt-2 text-sm text-steel">Private brains stay isolated — pick an agent to browse its tree.</p>

        @if ($agents->isEmpty())
            <p class="mt-6 text-sm text-steel">No agents yet — create one first.</p>
        @else
            <table class="mt-6 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">agent</th>
                        <th class="py-2 pr-4">files</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($agents as $agent)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream">{{ $agent->name }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $agent->files_count }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.files.show', $agent) }}" class="text-amber underline decoration-border underline-offset-4">browse</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>
</x-console-layout>
