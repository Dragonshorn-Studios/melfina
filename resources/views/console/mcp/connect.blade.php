<x-console-layout title="Connect MCP" :agent="null">
    <x-console-panel>
        <x-page-header eyebrow="MCP" title="Connect the whole fleet">
        </x-page-header>
        <p class="mt-3 text-sm text-steel">
            One MCP install covers every agent on this Melfina. The <strong class="text-cream">fleet key</strong>
            (<code>melfg_…</code>, managed on the
            <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.dashboard') }}">dashboard</a>)
            opens the shared <code>/mcp</code> endpoint but carries no persona — each agent says who it is with its
            <strong class="text-cream">name</strong>, a non-secret id it knows from its own instructions. For a
            dedicated single-agent setup instead, see that agent's <em>Connect</em> tab.
        </p>
        @if ($endpointIsLocal)
            <p class="mt-3 border border-red-500/60 bg-void/40 px-3 py-2 font-mono text-xs text-red-400" style="border-radius: var(--radius-console)">
                endpoint resolves to localhost — set APP_URL to this install's public URL, or remote
                agents get a package pointing at their own machine.
            </p>
        @endif
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
                Claude plugin marketplaces, …) — paste a URL below. It's public but unguessable, and the
                package carries <em>no</em> credentials: each agent fills <code>YOUR_KEY</code>
                in the shipped <code>mcp.json</code> itself. Two layouts:
                <strong class="text-cream">multi-skill</strong> (one <code>SKILL.md</code> per tool) and
                <strong class="text-cream">single skill + script</strong> (umbrella skill plus a
                <code>scripts/melfina-call.mjs</code> Node shim that validates args, retries, and
                reports readable errors).
            </p>
            <p class="mt-3 font-mono text-[10px] tracking-[0.12em] text-steel">MULTI-SKILL</p>
            <pre class="mt-1 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $publicRepoUrl }}</pre>
            <p class="mt-2 font-mono text-[10px] tracking-[0.12em] text-steel">SINGLE SKILL + SCRIPT</p>
            <pre class="mt-1 overflow-x-auto border border-border bg-void/60 p-3 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $publicRepoUrlSingle }}</pre>
            <p class="mt-2 text-sm text-steel">
                Importer insists on GitHub or a file upload (MiniMax)? Download the same package as a ZIP —
                <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.mcp.plugin.zip') }}">multi-skill</a>
                or
                <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.mcp.plugin.zip', ['variant' => 'single']) }}">single skill + script</a> —
                or publish it straight to a GitHub repo below.
                Prefer the key baked in instead? <code>{{ $fleetRepoUrl }}</code> embeds the fleet key
                (<code>{{ $fleetRepoUrlSingle }}</code> for the single layout) —
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
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">SHARED-KEY CLIENTS (ONE CONFIG, MANY IMPS)</summary>
                <p class="mt-3 text-sm text-steel">
                    Bots like grokbot share one connection config across every imp — nothing per-agent can be
                    set at all. Point that config at the endpoint with just the fleet key: discovery
                    (<code>initialize</code>, <code>tools/list</code>) works persona-less, and each imp names
                    itself per call — tell it <em>"you are &lt;name&gt;"</em> in its instructions, and every
                    tool accepts a <code>name</code> argument that becomes its persona on a fleet-authenticated
                    connection. On a dedicated <code>melf_…</code> key a mismatched
                    <code>name</code> fails instead of silently impersonating.
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
                    <li>Or skip OAuth and send the fleet key + the agent's name directly:</li>
                </ol>
                <pre class="{{ $preClass }}" style="border-radius: var(--radius-console)">{{ $snippets['claudeCodeKey'] }}</pre>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">CURSOR</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>For OAuth, add <code>https://www.cursor.com</code> to the allowed redirect origins below — Cursor registers a fixed callback there.</li>
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
                    <li>If the flow redirects to a <code>chatgpt.com</code> callback that fails, allow that origin below (<code>chatgpt-desktop://</code> is already allowed).</li>
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
                    <li>If Grok's redirect lands on a <code>grok.com</code> origin, allow it below first.</li>
                </ol>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">MINIMAX (MAVIS)</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>MiniMax does not run the OAuth flow — send the fleet key as Bearer plus the agent's name in <code>X-Melfina-Agent-Key</code>. Or install the plugin package from the URL above.</li>
                    <li>Edit <code>~/.mavis/mcp/mcp.json</code> and add a <code>melfina</code> entry (the file format varies between MiniMax versions — check <code>mavis mcp list</code> after saving):</li>
                </ol>
                <pre class="{{ $preClass }}" style="border-radius: var(--radius-console)">{{ $snippets['minimax'] }}</pre>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel" start="3">
                    <li>Replace <code>NAME</code> and <code>melfg_…</code>, then restart the agent.</li>
                </ol>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">ANY OTHER CLIENT</summary>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-steel">
                    <li>Transport: Streamable HTTP at the endpoint URL above.</li>
                    <li>Auth: OAuth 2.1 with DCR — clients auto-register via <code>POST /oauth/register</code>; discovery is at <code>/.well-known/oauth-protected-resource</code> and <code>/.well-known/oauth-authorization-server</code>. PKCE S256 only.</li>
                    <li>No OAuth support? Send <code>Authorization: Bearer melfg_…</code> (fleet key) plus <code>X-Melfina-Agent-Key</code> with the agent's name or key on every request. A dedicated config may send <code>Bearer melf_…</code> alone instead.</li>
                </ol>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)">
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">NO MCP? HTTP TOOL FALLBACK</summary>
                <p class="mt-3 text-sm text-steel">
                    Runtimes that can't run an MCP server call the same tools over plain JSON —
                    <code>GET {{ url('/api/tools') }}</code> lists the surface;
                    <code>POST {{ url('/api/tools') }}/&lt;tool&gt;</code> invokes it with the tool's
                    arguments object as the body (the same shape as MCP <code>tools/call</code>).
                    MCP stays the canonical path — prefer it whenever the client supports it, and
                    never call both for the same operation.
                </p>
                <p class="mt-2 text-sm text-steel">
                    Auth is the same Bearer/Basic credential surface as above, plus scoped
                    <code>melfs_…</code> keys for least-privilege cloud agents — issue them on each
                    agent's About tab.
                    Every package also ships a machine-readable <code>melfina-api.json</code>.
                    The <em>multi-skill</em> layout adds one generated
                    <code>skills/melfina-&lt;tool&gt;/SKILL.md</code> per tool; the
                    <em>single skill + script</em> layout instead ships
                    <code>scripts/melfina-call.mjs</code>, a zero-dependency Node shim agents run as
                    <code>node scripts/melfina-call.mjs call &lt;tool&gt; '&lt;args&gt;'</code> — it reads
                    the manifest, checks arguments, retries throttled calls, and prints actionable
                    errors.
                </p>
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)" open>
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">PUBLISH TO GITHUB</summary>
                <p class="mt-3 text-sm text-steel">
                    Some importers (MiniMax) only accept <code>github.com</code> URLs. Save a GitHub
                    personal access token (repo scope) and a target repo — <code>name</code> or
                    <code>owner/name</code> — then publish: the credential-free package is pushed to
                    <code>github.com/you/repo</code> (created public if missing). The token is stored
                    encrypted and never shown again.
                </p>
                <form method="POST" action="{{ route('console.mcp.github.store') }}" class="mt-3 flex flex-wrap gap-2">
                    @csrf
                    <input type="password" name="github_token" autocomplete="off"
                        placeholder="{{ $hasGitHubToken ? '•••••••• (saved — enter to replace)' : 'GitHub PAT (repo scope)' }}"
                        class="min-w-0 flex-1 border border-border bg-void/60 px-3 py-1.5 font-mono text-xs text-cream placeholder:text-steel/60"
                        style="border-radius: var(--radius-console)">
                    <input type="text" name="github_repo" placeholder="you/melfina-plugin" required
                        value="{{ $githubRepo }}"
                        class="min-w-0 flex-1 border border-border bg-void/60 px-3 py-1.5 font-mono text-xs text-cream placeholder:text-steel/60"
                        style="border-radius: var(--radius-console)">
                    <button type="submit"
                        class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                        style="border-radius: var(--radius-console)">save</button>
                </form>
                @if ($hasGitHubToken && $githubRepo)
                    <x-confirm-form action="{{ route('console.mcp.github.publish') }}" class="mt-2 flex items-center gap-2" confirm="Publish the fleet package to github.com/{{ $githubRepo }}? The repo is created public if it doesn't exist.">
                        <select name="variant"
                            class="border border-border bg-void px-2 py-1.5 font-mono text-xs text-cream"
                            style="border-radius: var(--radius-console)">
                            <option value="multi">multi-skill</option>
                            <option value="single">single skill + script</option>
                        </select>
                        <button type="submit"
                            class="border border-amber/50 bg-void px-3 py-1.5 font-mono text-xs text-amber hover:border-amber"
                            style="border-radius: var(--radius-console)">publish to github.com/{{ $githubRepo }}</button>
                    </x-confirm-form>
                @endif
                @error('github_repo')
                    <p class="mt-2 font-mono text-xs text-red-400">{{ $message }}</p>
                @enderror
                @error('github_token')
                    <p class="mt-2 font-mono text-xs text-red-400">{{ $message }}</p>
                @enderror
            </details>

            <details class="{{ $guideClass }}" style="border-radius: var(--radius-console)" open>
                <summary class="cursor-pointer font-mono text-xs tracking-[0.12em] text-cream">OAUTH REDIRECT ORIGINS</summary>
                <p class="mt-3 text-sm text-steel">
                    Dynamic client registration only accepts callback URLs on allowed origins —
                    <code>scheme://host[:port]</code>, no path. Loopback is always allowed for local
                    clients; add remote origins here instead of editing <code>MCP_REDIRECT_DOMAINS</code>
                    and redeploying.
                </p>
                @if (session('status'))
                    <p class="mt-3 font-mono text-xs text-amber">{{ session('status') }}</p>
                @endif
                <ul class="mt-3 space-y-1 font-mono text-xs">
                    @foreach ($envRedirectDomains as $domain)
                        <li class="flex items-center justify-between gap-3 border border-border/50 bg-void/60 px-3 py-1.5 text-steel" style="border-radius: var(--radius-console)">
                            <span>{{ $domain }}</span>
                            <span class="text-[10px] tracking-[0.12em]">ENV</span>
                        </li>
                    @endforeach
                    @foreach ($storedRedirectDomains as $domain)
                        <li class="flex items-center justify-between gap-3 border border-amber/40 bg-void/60 px-3 py-1.5 text-cream" style="border-radius: var(--radius-console)">
                            <span>{{ $domain }}</span>
                            <x-confirm-form action="{{ route('console.mcp.redirect-domains.destroy') }}" method="DELETE" confirm="Remove {{ $domain }}? Clients can no longer register callbacks on it.">
                                <input type="hidden" name="domain" value="{{ $domain }}">
                                <button type="submit" class="font-mono text-[10px] tracking-[0.12em] text-red-400 hover:text-red-300">REMOVE</button>
                            </x-confirm-form>
                        </li>
                    @endforeach
                </ul>
                <form method="POST" action="{{ route('console.mcp.redirect-domains.store') }}" class="mt-3 flex gap-2">
                    @csrf
                    <input type="text" name="domain" placeholder="https://chatgpt.com" required
                        class="min-w-0 flex-1 border border-border bg-void/60 px-3 py-1.5 font-mono text-xs text-cream placeholder:text-steel/60"
                        style="border-radius: var(--radius-console)">
                    <button type="submit"
                        class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                        style="border-radius: var(--radius-console)">allow origin</button>
                </form>
                @error('domain')
                    <p class="mt-2 font-mono text-xs text-red-400">{{ $message }}</p>
                @enderror
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
    </x-console-panel>
</x-console-layout>
