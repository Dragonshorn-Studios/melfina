<x-console-layout :title="'Groups'">
    <x-console-panel>
        <x-page-header eyebrow="GROUPS" title="Shared preamble and main person">
            <x-slot:subtitle>Members share an instruction preamble and, when they agree, one main person. A group grant on a shared space covers that space and its files.</x-slot:subtitle>
            <x-slot:actions>
                <a href="{{ route('console.groups.create') }}"
                    class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                    style="border-radius: var(--radius-console)">new group</a>
            </x-slot:actions>
        </x-page-header>

        @if (session('status'))
            <p class="mt-3 font-mono text-xs text-amber">{{ session('status') }}</p>
        @endif

        @if ($groups->isEmpty())
            <p class="mt-6 text-sm text-steel">No groups yet.</p>
        @else
            <table class="mt-6 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">name</th>
                        <th class="py-2 pr-4">members</th>
                        <th class="py-2 pr-4">main person</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $group)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream">{{ $group->name }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $group->agents_count }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $group->mainPerson?->name ?? '—' }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.groups.edit', $group) }}" class="text-amber underline decoration-border underline-offset-4">open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-console-panel>
</x-console-layout>
