<x-console-layout :title="'Instructions'">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">INSTRUCTIONS</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">Shape the brains</h1>
        <p class="mt-2 text-sm text-steel">Owner text plus ordered sub-layers per agent. Agents read these via MCP — they can never edit them.</p>

        @if ($agents->isEmpty())
            <p class="mt-6 text-sm text-steel">No agents yet — create one first.</p>
        @else
            <table class="mt-6 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">agent</th>
                        <th class="py-2 pr-4">instructions</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($agents as $agent)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream">{{ $agent->name }}</td>
                            <td class="py-2 pr-4 {{ $agent->instruction_exists ? 'text-amber' : 'text-steel' }}">{{ $agent->instruction_exists ? 'set' : 'empty' }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.agents.instructions.edit', $agent) }}" class="text-amber underline decoration-border underline-offset-4">edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>
</x-console-layout>
