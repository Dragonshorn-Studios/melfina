<x-console-layout :title="'Dashboard'">
    @if (session('plaintext_mcp_key'))
        <div id="plaintext-mcp-key" class="mb-6 border border-amber bg-void p-4" style="border-radius: var(--radius-console)">
            <div class="flex items-center justify-between">
                <p class="font-mono text-xs tracking-[0.16em] text-amber">FLEET KEY — SHOWN ONCE</p>
                <button type="button" onclick="document.getElementById('plaintext-mcp-key').remove()"
                    class="border border-border px-2 py-1 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                    style="border-radius: var(--radius-console)">dismiss</button>
            </div>
            <p class="mt-2 select-all break-all font-mono text-sm text-cream">{{ session('plaintext_mcp_key') }}</p>
            <p class="mt-2 font-mono text-xs text-steel">Copy it now. Only the hash is stored; we cannot show it again. Shared MCP configs send it as the Bearer credential.</p>
        </div>
    @endif

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
            <div class="flex items-center justify-between">
                <p class="font-mono text-xs tracking-[0.16em] text-steel">MCP ACCESS</p>
            </div>
            <form method="POST" action="{{ route('console.mcp-key.store') }}" class="mt-3 flex gap-2"
                onsubmit="return window.__melfinaConfirm || confirm(this.dataset.confirm)" data-confirm="Issue a fleet key under this name? An existing live key with the same name is revoked.">
                @csrf
                <input type="text" name="name" placeholder="key name (e.g. grokbot)" maxlength="64"
                    class="min-w-0 flex-1 border border-border bg-void/60 px-3 py-1.5 font-mono text-xs text-cream placeholder:text-steel/60"
                    style="border-radius: var(--radius-console)">
                <button type="submit"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">issue key</button>
            </form>
            @error('name')
                <p class="mt-2 font-mono text-xs text-red-400">{{ $message }}</p>
            @enderror
            <p class="mt-3 text-sm text-steel">
                The fleet key (<code>melfg_…</code>) is the shared credential you put in a shared MCP config:
                it opens the <code>/mcp</code> endpoint but carries no persona — discovery works without one,
                and each agent says who it is inside the call (the <code>name</code> argument), or with
                <code>X-Melfina-Agent-Key</code> / <code>?agent_key=</code> carrying its name or key — that is
                what gives every agent or discussion its own brain on a shared server.
                <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.mcp.connect') }}">Global install guide →</a>
            </p>
            <dl class="mt-4 space-y-2 font-mono text-xs">
                @if ($owner->mcp_key_hash)
                    <div class="flex gap-4">
                        <dt class="w-28 text-steel">fleet key</dt>
                        <dd class="text-cream">
                            @if ($owner->mcp_key_issued_at)
                                issued {{ $owner->mcp_key_issued_at->diffForHumans(short: true) }} (legacy)
                            @else
                                issued (legacy)
                            @endif
                        </dd>
                    </div>
                @endif
            </dl>
            @if ($fleetKeys->isNotEmpty())
                <ul class="mt-3 space-y-1 font-mono text-xs">
                    @foreach ($fleetKeys as $key)
                        <li class="flex items-center justify-between gap-3 border {{ $key->isLive() ? 'border-amber/40' : 'border-border/50' }} bg-void/60 px-3 py-1.5" style="border-radius: var(--radius-console)">
                            <span class="{{ $key->isLive() ? 'text-cream' : 'text-steel line-through' }}">{{ $key->name }}</span>
                            <span class="flex items-center gap-3">
                                <span class="text-steel">{{ $key->isLive() ? 'issued '.$key->created_at->diffForHumans(short: true) : 'revoked '.$key->revoked_at->diffForHumans(short: true) }}</span>
                                @if ($key->isLive())
                                    <form method="POST" action="{{ route('console.mcp-key.destroy', $key) }}"
                                        onsubmit="return window.__melfinaConfirm || confirm(this.dataset.confirm)" data-confirm="Revoke fleet key '{{ $key->name }}'? Shared configs using it stop working immediately.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="font-mono text-[10px] tracking-[0.12em] text-red-400 hover:text-red-300">REVOKE</button>
                                    </form>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

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
