<x-console-layout :title="'Shared'">
    <x-console-panel>
        <x-page-header eyebrow="SHARED" title="Shared folders">
            <x-slot:subtitle>You set who can read and write across agents.</x-slot:subtitle>
            <x-slot:actions>
<a href="{{ route('console.shared.create') }}"
                class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                style="border-radius: var(--radius-console)">new space</a>
            </x-slot:actions>
        </x-page-header>

        @if ($spaces->isEmpty())
            <p class="mt-6 text-sm text-steel">No shared spaces yet.</p>
        @else
            <table class="mt-6 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">name</th>
                        <th class="py-2 pr-4">grants</th>
                        <th class="py-2 pr-4">files</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($spaces as $space)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream">{{ $space->name }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $space->acl_entries_count + $space->group_acl_entries_count }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $space->files_count }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.shared.show', $space) }}" class="mr-3 text-amber underline decoration-border underline-offset-4">browse</a>
                                <a href="{{ route('console.shared.edit', $space) }}" class="text-amber underline decoration-border underline-offset-4">acl</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-console-panel>
</x-console-layout>
