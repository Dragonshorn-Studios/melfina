<x-console-layout :title="'Shared'">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">SHARED</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">Shared folders</h1>
                <p class="mt-2 text-sm text-steel">You set who can read and write. Not mail — that's ImpPost.</p>
            </div>
            <a href="{{ route('console.shared.create') }}"
                class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                style="border-radius: var(--radius-console)">new space</a>
        </div>

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
                            <td class="py-2 pr-4 text-steel">{{ $space->acl_entries_count }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $space->files_count }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.shared.edit', $space) }}" class="text-amber underline decoration-border underline-offset-4">acl</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>
</x-console-layout>
