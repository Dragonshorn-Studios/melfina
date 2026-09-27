<x-console-layout :title="'Connect — '.$agent->name" :agent="$agent">
    <x-console-panel>
        <x-page-header eyebrow="CONNECT" title="{{ $agent->name }} — its URLs">
        </x-page-header>
        <p class="mt-3 text-sm text-steel">
            Everything below is this agent's half of a connection — the fleet key
            (<code>melfg_…</code>, issued on the
            <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.dashboard') }}">dashboard</a>)
            is the other half. Per-client install guides (OAuth, plugin packages, header
            clients, the HTTP tool fallback) live once on the
            <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.mcp.connect') }}">fleet mcp page</a>.
        </p>

        <p class="mt-6 font-mono text-xs tracking-[0.16em] text-steel">MCP ENDPOINT</p>
        <pre class="mt-2 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $endpoint }}</pre>

        <p class="mt-6 font-mono text-xs tracking-[0.16em] text-steel">URL-ONLY CLIENTS</p>
        <p class="mt-2 text-sm text-steel">
            Persona and fleet key folded into the endpoint — username is this agent's name,
            password the fleet key:
        </p>
        <pre class="mt-2 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $endpointPersonaUrl }}</pre>

        <p class="mt-6 font-mono text-xs tracking-[0.16em] text-steel">HEADER CLIENTS</p>
        <p class="mt-2 text-sm text-steel">
            Send the fleet key as Bearer plus this agent's name — or its <code>melf_…</code>
            key (shown once on the
            <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.agents.show', $agent) }}">About</a>
            tab) — on every request:
        </p>
        <pre class="mt-2 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">Authorization: Bearer melfg_…
X-Melfina-Agent-Key: {{ $agent->name }}</pre>
        <p class="mt-2 text-sm text-steel">
            A dedicated config can instead send <code>Authorization: Bearer melf_…</code> alone —
            the agent key already carries the persona.
        </p>

        <div class="mt-8 border border-amber/50 bg-void/40 p-4" style="border-radius: var(--radius-console)">
            <p class="font-mono text-xs tracking-[0.12em] text-amber">THIS AGENT'S PLUGIN PACKAGE</p>
            <p class="mt-2 text-sm text-steel">
                For clients that install MCP servers from a plugin repo — substitute the key
                after the <code>:</code>. Credentials ride as HTTP Basic userinfo, so they stay
                out of request-path logs:
            </p>
            <pre class="mt-3 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $pluginUrl }}</pre>
            <p class="mt-2 text-sm text-steel">
                Without a fleet key, <code>{{ $pluginUrlDirect }}</code> works too — it carries
                just the agent key. One package for every agent instead:
                <code>{{ $pluginUrlFleet }}</code>.
                @if ($hasPackage)
                    For upload-only flows,
                    <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.agents.plugin', $agent) }}">download the plugin ZIP</a>
                    (it contains live keys — treat it like a password).
                @else
                    The upload-flow ZIP is built on key create/rotate — rotate this agent's key once
                    from the <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.agents.show', $agent) }}">About</a> tab to enable it.
                @endif
            </p>
        </div>

        <p class="mt-6 text-sm text-steel">
            No MCP? The same tools are reachable over plain JSON —
            <code>GET {{ $apiToolsIndex }}</code> lists them,
            <code>POST {{ $apiToolsIndex }}/&lt;tool&gt;</code> invokes one with the same
            body shape as <code>tools/call</code>. Scoped <code>melfs_…</code> keys for
            least-privilege agents are issued on the
            <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.agents.show', $agent) }}">About</a> tab.
        </p>
    </x-console-panel>
</x-console-layout>
