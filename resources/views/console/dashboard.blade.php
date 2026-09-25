<x-console-layout :title="'Console'">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">OWNER CONSOLE</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">Navigator online</h1>
        <p class="mt-2 text-sm leading-relaxed text-steel">
            Agents, files, shared spaces, and instructions land here as their tickets ship.
        </p>
        <p class="mt-4 font-mono text-xs text-steel">
            logged in as <span class="text-cream">{{ auth()->user()->email }}</span>
        </p>
    </main>
</x-console-layout>
