<x-console-layout :title="'Dashboard'">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">DASHBOARD</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">Fleet at a glance</h1>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('console.agents.create') }}"
                    class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                    style="border-radius: var(--radius-console)">new agent</a>
                <a href="{{ route('console.shared.create') }}"
                    class="border border-border px-3 py-1.5 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                    style="border-radius: var(--radius-console)">new space</a>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="border border-border/60 bg-void/40 px-4 py-3" style="border-radius: var(--radius-console)">
                <p class="font-mono text-[10px] tracking-[0.14em] text-steel">AGENTS</p>
                <p class="mt-1 text-2xl font-semibold text-cream">{{ $stats['agents'] }}</p>
            </div>
            <div class="border border-border/60 bg-void/40 px-4 py-3" style="border-radius: var(--radius-console)">
                <p class="font-mono text-[10px] tracking-[0.14em] text-steel">ACTIVE</p>
                <p class="mt-1 text-2xl font-semibold text-amber">{{ $stats['active'] }}</p>
            </div>
            <div class="border border-border/60 bg-void/40 px-4 py-3" style="border-radius: var(--radius-console)">
                <p class="font-mono text-[10px] tracking-[0.14em] text-steel">PRIVATE FILES</p>
                <p class="mt-1 text-2xl font-semibold text-cream">{{ $stats['files'] }}</p>
            </div>
            <div class="border border-border/60 bg-void/40 px-4 py-3" style="border-radius: var(--radius-console)">
                <p class="font-mono text-[10px] tracking-[0.14em] text-steel">SHARED SPACES</p>
                <p class="mt-1 text-2xl font-semibold text-cream">{{ $stats['spaces'] }}</p>
            </div>
        </div>

        <section class="mt-8">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">AGENTS</p>
            @if ($agents->isEmpty())
                <p class="mt-4 text-sm text-steel">No agents yet — <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.agents.create') }}">create one</a>.</p>
            @else
                <table class="mt-3 w-full border-collapse font-mono text-xs">
                    <thead>
                        <tr class="border-b border-border text-left text-steel">
                            <th class="py-2 pr-4">name</th>
                            <th class="py-2 pr-4">status</th>
                            <th class="py-2 pr-4">files</th>
                            <th class="py-2 pr-4">key</th>
                            <th class="py-2 pr-4">last write</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($agents as $agent)
                            <tr class="border-b border-border/50">
                                <td class="py-2 pr-4 text-cream">{{ $agent->name }}</td>
                                <td class="py-2 pr-4 {{ $agent->isActive() ? 'text-amber' : 'text-danger' }}">{{ $agent->status }}</td>
                                <td class="py-2 pr-4 text-steel">{{ $agent->files_count }}</td>
                                <td class="py-2 pr-4 text-steel">
                                    @if ($agent->key_issued_at)
                                        issued {{ $agent->key_issued_at->diffForHumans(short: true) }}
                                    @elseif ($agent->key_hash)
                                        issued
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-steel">
                                    {{ $agent->files_max_updated_at ? \Illuminate\Support\Carbon::parse($agent->files_max_updated_at)->diffForHumans(short: true) : '—' }}
                                </td>
                                <td class="py-2 text-right">
                                    <a href="{{ route('console.files.show', $agent) }}" class="text-amber underline decoration-border underline-offset-4">open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="mt-8">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">SHARED SPACES</p>
            @if ($spaces->isEmpty())
                <p class="mt-4 text-sm text-steel">No shared spaces yet — <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.shared.create') }}">create one</a>.</p>
            @else
                <table class="mt-3 w-full border-collapse font-mono text-xs">
                    <thead>
                        <tr class="border-b border-border text-left text-steel">
                            <th class="py-2 pr-4">name</th>
                            <th class="py-2 pr-4">members</th>
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
        </section>
    </main>
</x-console-layout>
