<x-console-layout :title="'Dashboard'">
    @if (session('plaintext_mcp_key'))
        <x-key-banner id="plaintext-mcp-key" label="FLEET KEY — SHOWN ONCE" :value="session('plaintext_mcp_key')">
            <x-slot:hint>Copy it now. Only the hash is stored; we cannot show it again. Shared MCP configs send it as the Bearer credential.</x-slot:hint>
        </x-key-banner>
    @endif

    @if (session('plaintext_enroll_key'))
        <x-key-banner id="plaintext-enroll-key" label="ENROLL KEY — SHOWN ONCE" :value="session('plaintext_enroll_key')">
            <x-slot:hint>Copy it now. Only the hash is stored; we cannot show it again. Agents send it as <code>secret</code> to POST /api/enroll.</x-slot:hint>
        </x-key-banner>
    @endif

    <x-console-panel>
        <x-page-header eyebrow="DASHBOARD" title="Fleet at a glance">
            <x-slot:actions>
<div class="flex gap-2">
                <a href="{{ route('console.agents.create') }}"
                    class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                    style="border-radius: var(--radius-console)">new agent</a>
                <a href="{{ route('console.shared.create') }}"
                    class="border border-border px-3 py-1.5 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                    style="border-radius: var(--radius-console)">new space</a>
            </div>
            </x-slot:actions>
        </x-page-header>
        @if (session('status'))
            <p class="mt-2 font-mono text-xs text-amber">{{ session('status') }}</p>
        @endif

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
            <x-confirm-form action="{{ route('console.mcp-key.store') }}" class="mt-3 flex gap-2" confirm="Issue a fleet key under this name? An existing live key with the same name is revoked."><input type="text" name="name" placeholder="key name (e.g. grokbot)" maxlength="64"
                    class="min-w-0 flex-1 border border-border bg-void/60 px-3 py-1.5 font-mono text-xs text-cream placeholder:text-steel/60"
                    style="border-radius: var(--radius-console)">
                <button type="submit"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">issue key</button>
            </x-confirm-form>
            @error('name')
                <p class="mt-2 font-mono text-xs text-red-400">{{ $message }}</p>
            @enderror
            <p class="mt-3 text-sm text-steel">
                The fleet key (<code>melfg_…</code>) is the shared credential you put in a shared MCP config:
                it opens the <code>/mcp</code> endpoint but carries no persona — discovery works without one,
                and each agent says who it is inside the call (the <code>name</code> argument), or with
                <code>X-Melfina-Agent-Key</code> carrying its name or key / <code>?agent_key=</code>
                carrying its name only — that is what gives every agent or discussion its own brain
                on a shared server.
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
                                    <x-confirm-form action="{{ route('console.mcp-key.destroy', $key) }}" method="DELETE" confirm="Revoke fleet key '{{ $key->name }}'? Shared configs using it stop working immediately.">
                                        <button type="submit" class="font-mono text-[10px] tracking-[0.12em] text-red-400 hover:text-red-300">REVOKE</button>
                                    </x-confirm-form>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="mt-8">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">ENROLLMENT KEYS</p>
            <x-confirm-form action="{{ route('console.enroll-keys.store') }}" class="mt-3 flex gap-2" confirm="Issue an enroll key under this name? An existing live key with the same name is revoked."><input type="text" name="enroll_name" placeholder="key name (e.g. minimax)" maxlength="64"
                    class="min-w-0 flex-1 border border-border bg-void/60 px-3 py-1.5 font-mono text-xs text-cream placeholder:text-steel/60"
                    style="border-radius: var(--radius-console)">
                <button type="submit"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">issue key</button>
            </x-confirm-form>
            @error('enroll_name')
                <p class="mt-2 font-mono text-xs text-red-400">{{ $message }}</p>
            @enderror
            <p class="mt-3 text-sm text-steel">
                An enroll key (<code>melfe_…</code>) is the shared secret a runtime without secret storage
                presents to <code>POST /api/enroll</code> with its own <code>id</code> and claimed
                <code>name</code>. Reports land on the
                <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.agents.index') }}">agents accept list</a>;
                once accepted, each call mints a 24&nbsp;h scoped key. It never authenticates a tool call itself.
            </p>
            @if ($enrollKeys->isNotEmpty())
                <ul class="mt-3 space-y-1 font-mono text-xs">
                    @foreach ($enrollKeys as $key)
                        <li class="flex items-center justify-between gap-3 border {{ $key->isLive() ? 'border-amber/40' : 'border-border/50' }} bg-void/60 px-3 py-1.5" style="border-radius: var(--radius-console)">
                            <span class="{{ $key->isLive() ? 'text-cream' : 'text-steel line-through' }}">{{ $key->name }}</span>
                            <span class="flex items-center gap-3">
                                <span class="text-steel">{{ $key->isLive() ? 'issued '.$key->created_at->diffForHumans(short: true) : 'revoked '.$key->revoked_at->diffForHumans(short: true) }}</span>
                                @if ($key->isLive())
                                    <x-confirm-form action="{{ route('console.enroll-keys.destroy', $key) }}" method="DELETE" confirm="Revoke enroll key '{{ $key->name }}'? Runtimes using it can no longer report in or mint.">
                                        <button type="submit" class="font-mono text-[10px] tracking-[0.12em] text-red-400 hover:text-red-300">REVOKE</button>
                                    </x-confirm-form>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="mt-8">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">OPERATOR PERSONA</p>
            <p class="mt-1 text-sm text-steel">
                Who you are, appended to every agent's <code>read_instructions</code> — how the
                fleet should address its human. Leave empty to send nothing.
            </p>
            <form method="POST" action="{{ route('console.mcp.operator-persona.store') }}" class="mt-3">
                @csrf
                @method('PUT')
                <textarea name="operator_persona" rows="3"
                    placeholder="The user is Antoni — address them as Antoni; they run Dragonshorn and prefer terse replies."
                    class="w-full border border-border bg-void/60 px-3 py-2 font-mono text-xs text-cream placeholder:text-steel/60"
                    style="border-radius: var(--radius-console)">{{ old('operator_persona', $operatorPersona) }}</textarea>
                @error('operator_persona') <p class="mt-1 font-mono text-xs text-red-400">{{ $message }}</p> @enderror
                <button type="submit"
                    class="mt-2 border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">save persona</button>
            </form>
        </section>

        <section class="mt-8 flex items-center gap-6 border-t border-border pt-4 font-mono text-xs">
            <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.agents.index') }}">agent roster →</a>
            <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.shared.index') }}">shared spaces →</a>
        </section>
    </x-console-panel>
</x-console-layout>
