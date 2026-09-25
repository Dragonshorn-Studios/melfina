<x-console-layout :title="$agent->name">
    @if (session('plaintext_key'))
        <div class="mb-6 border border-amber bg-void p-4" style="border-radius: var(--radius-console)">
            <p class="font-mono text-xs tracking-[0.16em] text-amber">AGENT KEY — SHOWN ONCE</p>
            <p class="mt-2 select-all break-all font-mono text-sm text-cream">{{ session('plaintext_key') }}</p>
            <p class="mt-2 font-mono text-xs text-steel">Copy it now. Only the hash is stored; we cannot show it again.</p>
        </div>
    @endif

    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">AGENT</p>
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
                <dt class="w-28 text-steel">created</dt>
                <dd class="text-cream">{{ $agent->created_at->toDateTimeString() }}</dd>
            </div>
        </dl>

        <div class="mt-8 flex items-center gap-3">
            <a href="{{ route('console.agents.edit', $agent) }}"
                class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                style="border-radius: var(--radius-console)">rename / status</a>
            <form method="POST" action="{{ route('console.agents.rotate', $agent) }}"
                onsubmit="return confirm('Rotate key? The old key stops working immediately.');">
                @csrf
                <button type="submit"
                    class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                    style="border-radius: var(--radius-console)">rotate key</button>
            </form>
            <a href="{{ route('console.agents.index') }}" class="font-mono text-xs text-steel underline decoration-border underline-offset-4">back</a>
        </div>
    </main>
</x-console-layout>
