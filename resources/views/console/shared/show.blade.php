<x-console-layout :title="'Shared — '.$space->name">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">SHARED SPACE</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">{{ $space->name }}</h1>
                <p class="mt-2 text-sm text-steel">Everything agents have put in this folder — read-only.</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('console.shared.edit', $space) }}"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">acl</a>
                <a href="{{ route('console.shared.index') }}"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                    style="border-radius: var(--radius-console)">back</a>
            </div>
        </div>

        @if ($files->isEmpty())
            <p class="mt-6 text-sm text-steel">No files in this space yet.</p>
        @else
            <table class="mt-6 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">path</th>
                        <th class="py-2 pr-4">size</th>
                        <th class="py-2 pr-4">updated</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($files as $file)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream">{{ $file->path }}</td>
                            <td class="py-2 pr-4 text-steel">{{ strlen($file->content) }} B</td>
                            <td class="py-2 pr-4 text-steel">{{ $file->updated_at->toDateTimeString() }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.shared.file', ['shared' => $space, 'path' => $file->path]) }}"
                                    class="text-amber underline decoration-border underline-offset-4">open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>
</x-console-layout>
