<x-console-layout title="Connect MCP" :agent="null">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">MCP</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">Connect the whole fleet</h1>
        <p class="mt-3 text-sm text-steel">
            One MCP install covers every agent on this Melfina. The <strong class="text-cream">fleet key</strong>
            (<code>melfg_…</code>, managed on the
            <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.dashboard') }}">dashboard</a>)
            opens the shared <code>/mcp</code> endpoint but carries no persona — each agent says who it is with its
            <strong class="text-cream">name</strong>, a non-secret id it knows from its own instructions. For a
            dedicated single-agent setup instead, see that agent's <em>Connect</em> tab.
        </p>
        @unless ($hasFleetKey)
            <p class="mt-3 border border-amber/50 bg-void/40 px-3 py-2 font-mono text-xs text-amber" style="border-radius: var(--radius-console)">
                no fleet key yet — generate one on the dashboard first.
            </p>
        @endunless

        <pre class="mt-4 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $endpoint }}</pre>

        <div class="mt-8 border border-amber/50 bg-void/40 p-4" style="border-radius: var(--radius-console)">
            <p class="font-mono text-xs tracking-[0.12em] text-amber">INSTALL AS PLUGIN (ONE FOR EVERYONE)</p>
            <p class="mt-2 text-sm text-steel">
                For clients that install MCP servers from a plugin repo (MiniMax Code, mavis, zcode,
                Claude plugin marketplaces, …) — paste this URL. It's public but unguessable, and the
                package carries <em>no</em> credentials: each agent fills <code>YOUR_NAME</code> +
                <code>YOUR_KEY</code> in the shipped <code>mcp.json</code> itself:
            </p>
            <pre class="mt-3 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $publicRepoUrl }}</pre>
            <p class="mt-2 text-sm text-steel">
                Importer insists on GitHub or a file upload (MiniMax)?
                <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.mcp.plugin.zip') }}">Download the same package as a ZIP</a>.
                Prefer the key baked in instead? <code>{{ $fleetRepoUrl }}</code> embeds the fleet key —
                the clone needs Basic credentials, so use it only with clients that pass
                <code>user:pass@</code> URLs correctly.
            </p>
        </div>

        <div class="mt-8 space-y-4">
            @php
                $guideClass = 'border border-border/60 bg-void/40 p-4';
                $preClass = 'mt-3 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream';
            @endphp

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)" open>
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">URL-ONLY CLIENTS</summary>
                <p class="mt-3 text-sm text-steel">
                    Clients that only let you set a server URL get both credentials folded into it — the
                    Basic username is the persona, the password the fleet key. Replace
                    <code>NAME</code> and <code>melfg_…</code>:
                </p>
                <pre class="{{ $preClass }}" style="border-radius: var(--radius-console)">{{ $personaUrl }}</pre>
                <p class="mt-2 text-sm text-steel">
                    An empty username also works when the client can add <code>?agent_key=NAME</code> to the
                    query instead. Names containing <code>:</code> or <code>@</code> can't ride in a URL —
                    those agents use the header form below.
                </p>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">HEADER-BASED CONFIGS</summary>
                <p class="mt-3 text-sm text-steel">
                    Clients that let you set headers send the fleet key as Bearer plus the persona in
                    <code>X-Melfina-Agent-Key</code> (the agent's name, or its <code>melf_…</code> key):
                </p>
                <pre class="{{ $preClass }}" style="border-radius: var(--radius-console)">{{ $headersConfig }}</pre>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">OAUTH CLIENTS</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>OAuth clients don't need the fleet key: point them at the endpoint, they auto-register via <code>POST /oauth/register</code>, and the token is bound to one agent — chosen on the consent screen, which needs a one-time owner click <em>per bot</em>.</li>
                    <li>Headless bots that can't click through consent (grokbot-style, one connection per bot) use the fleet forms above instead — fleet key plus "you are &lt;name&gt;" via <code>X-Melfina-Agent-Key</code> or the Basic username.</li>
                    <li>Discovery: <code>/.well-known/oauth-protected-resource</code> and <code>/.well-known/oauth-authorization-server</code>. PKCE S256 only.</li>
                </ol>
            </details>
        </div>
    </main>
</x-console-layout>
