<x-console-layout :title="'Connect — '.$agent->name" :agent="$agent">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">CONNECT</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">{{ $agent->name }} — MCP endpoint</h1>
        <p class="mt-3 text-sm text-steel">
            Point MCP clients at this Streamable HTTP URL. Clients that speak OAuth get a browser consent
            screen where you bind the token to this agent. Static configs send two credentials: the install's
            <strong class="text-cream">fleet key</strong> (<code>melfg_…</code>, generate it on the
            <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.dashboard') }}">dashboard</a>)
            as the Bearer credential, plus this agent's persona — its <strong class="text-cream">name</strong>
            or its key (<code>melf_…</code>, shown once on the
            <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.agents.show', $agent) }}">Key</a> tab)
            as <code>X-Melfina-Agent-Key</code> (or <code>?agent_key=</code> — names only there, keys
            stay out of query strings). The name is not a secret — the agent just has to know it.
            On a shared config the fleet key is identical for every agent — the persona is what gives each
            one its brain. URL-only clients can fold both into the endpoint itself:
            <code>{{ $endpointPersonaUrl }}</code> —
            the Basic username is the persona, the password the fleet key.
        </p>
        <pre class="mt-4 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $endpoint }}</pre>

        <div class="mt-8 border border-amber/50 bg-void/40 p-4" style="border-radius: var(--radius-console)">
            <p class="font-mono text-xs tracking-[0.12em] text-amber">INSTALL AS PLUGIN</p>
            <p class="mt-2 text-sm text-steel">
                For clients that install MCP servers from a plugin repo (MiniMax Code, mavis, zcode,
                Claude plugin marketplaces, …) Melfina serves a ready-made package as a git URL —
                substitute the fleet key after the <code>:</code>. The credentials travel as HTTP
                Basic auth, not in the request path, so they stay out of server access logs:
            </p>
            <pre class="mt-3 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $pluginUrl }}</pre>
            <p class="mt-2 text-sm text-steel">
                Without a fleet key, <code>{{ $pluginUrlDirect }}</code> works too — the package
                then carries just the agent key. And for a multi-agent setup on one provider,
                <code>{{ $pluginUrlFleet }}</code> is a single fleet-wide package: the fleet key
                opens the endpoint and each imp names itself with the <code>name</code> argument
                on every tool call.
                @if ($hasPackage)
                    For upload-only flows,
                    <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.agents.plugin', $agent) }}">download the plugin ZIP</a>
                    (it contains live keys — treat it like a password).
                @else
                    The upload-flow ZIP is built on key create/rotate — rotate this agent's key once
                    from the <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.agents.show', $agent) }}">Key</a> tab to enable it.
                @endif
            </p>
        </div>

        <div class="mt-8 space-y-4">
            @php
                $guideClass = 'border border-border/60 bg-void/40 p-4';
                $preClass = 'mt-3 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream';
            @endphp

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)" open>
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">CLAUDE DESKTOP</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>Edit Claude Desktop's config (macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>; Windows: <code>%APPDATA%\Claude\claude_desktop_config.json</code>).</li>
                    <li>Add a <code>melfina</code> entry that runs <code>npx mcp-remote</code> (Node 18+) against the endpoint — it bridges OAuth automatically.</li>
                    <li>Restart Claude Desktop and complete the browser consent when prompted.</li>
                </ol>
                <pre class="{{ $preClass }}" style="border-radius: var(--radius-console)">{{ $snippets['claudeDesktop'] }}</pre>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">CLAUDE CODE</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>Add the server with HTTP transport:</li>
                </ol>
                <pre class="{{ $preClass }}" style="border-radius: var(--radius-console)">{{ $snippets['claudeCode'] }}</pre>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel" start="2">
                    <li>If <code>claude mcp list</code> shows "Needs authentication", run <code>/mcp</code> in a session and choose Authenticate.</li>
                    <li>Or skip OAuth and send the fleet key + this agent's key directly:</li>
                </ol>
                <pre class="{{ $preClass }}" style="border-radius: var(--radius-console)">{{ $snippets['claudeCodeKey'] }}</pre>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">CURSOR</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>For OAuth, add <code>https://www.cursor.com</code> to <code>MCP_REDIRECT_DOMAINS</code> on the server first — Cursor registers a fixed callback there.</li>
                    <li>Edit <code>~/.cursor/mcp.json</code> (or Settings → Tools &amp; Integrations → MCP → Add Custom MCP):</li>
                </ol>
                <pre class="{{ $preClass }}" style="border-radius: var(--radius-console)">{{ $snippets['cursor'] }}</pre>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel" start="3">
                    <li>Save and refresh the server; complete the browser consent when Cursor prompts.</li>
                </ol>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">CHATGPT</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>Enable developer mode in ChatGPT (Settings → Connectors → Advanced).</li>
                    <li>Create a connector, paste the endpoint URL, and complete the OAuth consent in the browser.</li>
                    <li>If the flow redirects to a <code>chatgpt.com</code> callback that fails, add that origin to <code>MCP_REDIRECT_DOMAINS</code> (<code>chatgpt-desktop://</code> is already allowed).</li>
                </ol>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">OPENCODE</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>Edit <code>~/.config/opencode/opencode.json</code> or a project <code>opencode.json</code> — OAuth stays on by default for remote servers.</li>
                </ol>
                <pre class="{{ $preClass }}" style="border-radius: var(--radius-console)">{{ $snippets['openCode'] }}</pre>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel" start="2">
                    <li>Restart OpenCode and complete the browser consent when challenged.</li>
                </ol>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">GROK</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>Open grok.com → Connectors → New Connector → Custom.</li>
                    <li>Paste the endpoint URL and authenticate. The instance must be reachable on public HTTPS — Grok cannot call a LAN address.</li>
                    <li>If Grok's redirect lands on a <code>grok.com</code> origin, add it to <code>MCP_REDIRECT_DOMAINS</code> first.</li>
                </ol>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">MINIMAX (MAVIS)</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>MiniMax does not run the OAuth flow — send the fleet key as Bearer plus this agent's name in <code>X-Melfina-Agent-Key</code>. Or install the plugin package from the URL above.</li>
                    <li>Edit <code>~/.mavis/mcp/mcp.json</code> and add a <code>melfina</code> entry (the file format varies between MiniMax versions — check <code>mavis mcp list</code> after saving):</li>
                </ol>
                <pre class="{{ $preClass }}" style="border-radius: var(--radius-console)">{{ $snippets['minimax'] }}</pre>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel" start="3">
                    <li>Replace <code>melfg_…</code> with the fleet key, then restart the agent.</li>
                </ol>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">ANY OTHER CLIENT</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>Transport: Streamable HTTP at the endpoint URL above.</li>
                    <li>Auth: OAuth 2.1 with DCR — clients auto-register via <code>POST /oauth/register</code>; discovery is at <code>/.well-known/oauth-protected-resource</code> and <code>/.well-known/oauth-authorization-server</code>. PKCE S256 only.</li>
                    <li>No OAuth support? Send <code>Authorization: Bearer melfg_…</code> (fleet key) plus <code>X-Melfina-Agent-Key</code> with this agent's name or key on every request. A dedicated config may send <code>Bearer melf_…</code> alone instead.</li>
                </ol>
            </details>
        </div>
    </main>
</x-console-layout>
