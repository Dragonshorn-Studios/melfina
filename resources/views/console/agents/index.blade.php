<x-console-layout :title="'Agents'">
    <x-console-panel>
        <x-page-header eyebrow="AGENTS" title="One agent, one key, one file tree">
            <x-slot:actions>
<a href="{{ route('console.agents.create') }}"
                class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                style="border-radius: var(--radius-console)">new agent</a>
            </x-slot:actions>
        </x-page-header>

        @if ($agents->isEmpty())
            <p class="mt-6 text-sm text-steel">No agents yet.</p>
        @else
            <table class="mt-6 w-full border-collapse font-mono text-xs">
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
                            <td class="py-2 pr-4 text-steel">{{ $agent->key_hash ? 'issued' : '—' }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $agent->files_max_updated_at ? \Illuminate\Support\Carbon::parse($agent->files_max_updated_at)->diffForHumans(short: true) : '—' }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.agents.show', $agent) }}" class="text-amber underline decoration-border underline-offset-4">open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($enrollments->isNotEmpty())
            <section class="mt-8 border-t border-border pt-4">
                <p class="font-mono text-xs tracking-[0.16em] text-steel">ENROLLMENTS</p>
                <p class="mt-1 font-mono text-xs text-steel">Runtimes reporting in via POST /api/enroll. Accept mints 24&nbsp;h scoped keys (<span class="text-cream">melfs_…</span>); revoking kills the enrollment and every key it minted.</p>
                <ul class="mt-3 space-y-2 font-mono text-xs">
                    @foreach ($enrollments as $enrollment)
                        <li class="border {{ $enrollment->isPending() ? 'border-amber/40' : 'border-border/50' }} bg-void/60 px-3 py-2" style="border-radius: var(--radius-console)">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span>
                                    <span class="text-cream">{{ $enrollment->remote_id }}</span>
                                    <span class="text-steel"> claims </span>
                                    <span class="{{ $enrollment->isPending() ? 'text-cream' : 'text-steel' }}">{{ $enrollment->name }}</span>
                                    @if ($enrollment->agent)
                                        <span class="text-steel"> → </span>
                                        <a href="{{ route('console.agents.show', $enrollment->agent) }}" class="text-amber underline decoration-border underline-offset-4">{{ $enrollment->agent->name }}</a>
                                    @endif
                                </span>
                                <span class="{{ $enrollment->isPending() ? 'text-amber' : 'text-steel' }}">
                                    {{ $enrollment->status }} · {{ $enrollment->created_at->diffForHumans(short: true) }}
                                </span>
                            </div>
                            @if ($enrollment->isPending())
                                <form method="POST" action="{{ route('console.enrollments.accept', $enrollment) }}" class="mt-2">
                                    @csrf
                                    <label class="flex items-center gap-2 text-steel">
                                        agent name
                                        <input type="text" name="name" value="{{ $enrollment->name }}" required maxlength="255"
                                            class="border border-border bg-void px-2 py-1 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">
                                    </label>
                                    @if ($enrollment->claimedAgentExists())
                                        <p class="mt-1 text-amber">binds to the existing agent of that name</p>
                                    @endif
                                    <fieldset class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-steel">
                                        @foreach ($apiScopes as $scope)
                                            <label class="flex items-center gap-1">
                                                <input type="checkbox" name="scopes[]" value="{{ $scope }}" class="accent-amber" @checked($scope === 'all:read')>
                                                {{ $scope }}
                                            </label>
                                        @endforeach
                                    </fieldset>
                                    <div class="mt-2 flex gap-2">
                                        <button type="submit"
                                            class="border border-amber bg-amber px-3 py-1 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                                            style="border-radius: var(--radius-console)">accept</button>
                                    </div>
                                </form>
                            @endif
                            @if (! $enrollment->isRevoked())
                                <x-confirm-form action="{{ route('console.enrollments.revoke', $enrollment) }}" class="mt-2" confirm="Revoke enrollment '{{ $enrollment->remote_id }}'? Every key minted from it stops working immediately."><button type="submit" class="font-mono text-[10px] tracking-[0.12em] text-red-400 hover:text-red-300">
                                        {{ $enrollment->isPending() ? 'DECLINE' : 'REVOKE' }}
                                    </button>
                                </x-confirm-form>
                            @endif
                            @if ($enrollment->isAccepted() && $enrollment->scopes)
                                <p class="mt-2 text-steel">scopes: {{ implode(', ', $enrollment->scopes) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </x-console-panel>
</x-console-layout>
