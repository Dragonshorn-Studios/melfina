<x-console-layout :title="$agent->name" :agent="$agent">
    @if (session('plaintext_key'))
        <div id="plaintext-key" class="mb-6 border border-amber bg-void p-4" style="border-radius: var(--radius-console)">
            <div class="flex items-center justify-between">
                <p class="font-mono text-xs tracking-[0.16em] text-amber">{{ str_starts_with(session('plaintext_key'), 'melfs_') ? 'SCOPED API KEY — SHOWN ONCE' : 'AGENT KEY — SHOWN ONCE' }}</p>
                <button type="button" onclick="document.getElementById('plaintext-key').remove()"
                    class="border border-border px-2 py-1 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                    style="border-radius: var(--radius-console)">dismiss</button>
            </div>
            <p class="mt-2 select-all break-all font-mono text-sm text-cream">{{ session('plaintext_key') }}</p>
            <p class="mt-2 font-mono text-xs text-steel">Copy it now. Only the hash is stored; we cannot show it again.</p>
        </div>
    @endif

    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">ABOUT</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">{{ $agent->name }}</h1>
            </div>
            <span class="font-mono text-xs {{ $agent->isActive() ? 'text-amber' : 'text-danger' }}">{{ $agent->status }}</span>
        </div>

        <dl class="mt-6 space-y-2 font-mono text-xs">
            <div class="flex gap-4">
                <dt class="w-28 text-steel">key</dt>
                <dd class="text-cream">{{ $agent->key_hash ? 'issued (hashed)' : '—' }}</dd>
            </div>
            <div class="flex gap-4">
                <dt class="w-28 text-steel">description</dt>
                <dd class="text-cream">{{ $agent->description ?: '—' }}</dd>
            </div>
            <div class="flex gap-4">
                <dt class="w-28 text-steel">created</dt>
                <dd class="text-cream">{{ $agent->created_at->toDateTimeString() }}</dd>
            </div>
        </dl>

        <div class="mt-6 border-t border-border pt-4">
            <div class="flex items-center justify-between">
                <p class="font-mono text-xs tracking-[0.16em] text-steel">PERSONA &amp; MEMORY</p>
                <a href="{{ route('console.agents.persona.edit', $agent) }}"
                    class="font-mono text-xs text-amber underline decoration-border underline-offset-4">persona tab →</a>
            </div>
            <p class="mt-2 font-mono text-xs text-steel">
                @if ($agent->persona)
                    {{ Str::limit($agent->persona, 120) }}
                @else
                    nothing yet — the imp writes these itself via update_persona.
                @endif
            </p>
        </div>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">SCOPED API KEYS</p>
            <p class="mt-1 font-mono text-xs text-steel">Least-privilege <span class="text-cream">melfs_…</span> keys for agents calling the HTTP tool fallback (POST /api/tools/…). A key can only call tools inside its scopes.</p>

            @if ($apiKeys->isNotEmpty())
                <ul class="mt-3 space-y-1 font-mono text-xs">
                    @foreach ($apiKeys as $key)
                        <li class="flex items-center justify-between gap-3 border border-border/50 bg-void/60 px-3 py-1.5" style="border-radius: var(--radius-console)">
                            <div>
                                <span class="text-cream">{{ $key->name }}</span>
                                <span class="text-steel"> — {{ implode(', ', $key->scopes ?? []) }}</span>
                                @if ($key->expires_at)
                                    <span class="{{ $key->isLive() ? 'text-steel' : 'text-danger' }}"> · {{ $key->expires_at->isPast() ? 'expired' : 'expires' }} {{ $key->expires_at->diffForHumans() }}</span>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('console.agents.api-keys.destroy', [$agent, $key]) }}"
                                onsubmit="return window.__melfinaConfirm || confirm(this.dataset.confirm)" data-confirm="Revoke this key? It stops working immediately.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-steel underline decoration-border underline-offset-4 hover:text-danger">revoke</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 font-mono text-xs text-steel">none issued.</p>
            @endif

            <form method="POST" action="{{ route('console.agents.api-keys.store', $agent) }}" class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                <label class="font-mono text-xs text-steel">
                    name
                    <input type="text" name="key_name" required maxlength="60" placeholder="cloud-minimax"
                        class="mt-1 block border border-border bg-void px-2 py-1 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">
                </label>
                <fieldset class="flex w-full min-w-0 flex-wrap gap-x-3 gap-y-1 font-mono text-xs text-steel">
                    @foreach ($apiScopes as $scope)
                        <label class="flex items-center gap-1">
                            <input type="checkbox" name="scopes[]" value="{{ $scope }}" class="accent-amber">
                            {{ $scope }}
                            @if ($scope === 'all:read')
                                <span class="opacity-60">(every read scope)</span>
                            @elseif ($scope === 'all:write')
                                <span class="opacity-60">(all read + write)</span>
                            @endif
                        </label>
                    @endforeach
                </fieldset>
                <button type="submit"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">issue key</button>
            </form>
            @error('key_name')
                <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p>
            @enderror
            @error('scopes')
                <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p>
            @enderror
            @foreach ($errors->get('scopes.*') as $scopeErrors)
                <p class="mt-1 font-mono text-xs text-danger">{{ $scopeErrors[0] }}</p>
            @endforeach
        </div>

        <div class="mt-8 flex items-center gap-3">
            <a href="{{ route('console.agents.edit', $agent) }}"
                class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                style="border-radius: var(--radius-console)">rename / status</a>
            <form method="POST" action="{{ route('console.agents.rotate', $agent) }}"
                onsubmit="return window.__melfinaConfirm || confirm(this.dataset.confirm)" data-confirm="Rotate key? The old key stops working immediately.">
                @csrf
                <button type="submit"
                    class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                    style="border-radius: var(--radius-console)">rotate key</button>
            </form>
            <a href="{{ route('console.agents.index') }}" class="font-mono text-xs text-steel underline decoration-border underline-offset-4">back</a>
        </div>
    </main>
</x-console-layout>
