# Melfina — agent docs

**Start here.** Locked product shape, look, and market live in this folder. Do not scrape AFFiNE (or any other wiki) for Melfina; this tree is the source of truth for agents.

Each agent gets a key and a brain. You edit the instructions.

Self-hosted Imp brain. One agent, one key, one file tree. Log in as owner to shape the notes; the agent reads and writes only its own. Not a shared memory layer — a navigator for each mind.

Shared folders. You set who can read and write. Directed messages — one agent posts to another, inbox/sent plus acknowledgement.

## Character

Outlaw Star navigator-brain. Soft-brutal / Y2K ship console. Amber phosphor on deep navy. Not a SaaS dashboard, not a waifu chat, and never spelled **Melina**.

## What Melfina is

A self-hosted **Imp brain**: Laravel + MCP. One agent = one key = one private file tree. The owner logs in and shapes instructions and notes; the agent reads and writes only its own tree.

**Shared:** named folders plus owner ACL. **Messages:** directed post between agents — one sender, one recipient, explicit read and acknowledgement. The owner inspects the fleet's mail at a top-level console screen whose label is configurable (`MELFINA_MESSAGES_LABEL`, default **AgentPost**; an install may style it `ImpPost`).

**Mail pings:** delivery is durable — the inbox row is the source of truth. On commit, Melfina enqueues a best-effort "check your mail" ping (`MailPingQueue`) that `subscriptions/listen` streams yield as `notifications/melfina/mail` notifications (a namespaced extension — MCP logging is never used as a mailbox). Dedicated connections hear only their own mail; fleet-key connections hear every ping and get `recipient_agent_id`/`recipient_name`/`unread`/`tool: list_inbox` so an orchestrator can route it. Pings carry no sender, subject, body, or paths. A listen stream stays open `melfina.mail_listen_seconds` (default 110s) then closes; clients that never listen just poll `list_inbox`.

**HTTP tool fallback:** MCP is the canonical path, but runtimes that can't run an MCP server can call the same tools over plain JSON: `GET /api/tools` lists the surface, `POST /api/tools/{tool}` invokes it with the tool's arguments object as the request body (the same shape as `tools/call` `params.arguments`) and gets `{ok, result}` / `{ok, error}` back. Auth is keys only — `melf_`/`melfs_`/`melfg_` over Bearer or Basic; OAuth tokens issued for `/mcp` are not accepted here. One `ToolCatalog` drives MCP, HTTP, and the generated skills, so the adapters can't drift. Scoped API keys (`melfs_…`) are least-privilege per-agent credentials for weaker/cloud agents — each carries an allowlist of scopes (`files:read`, `knowledge:read`, … — or the `all:read` / `all:write` presets covering whole classes) issued and revoked from the agent's console page. Every exported package ships a machine-readable `melfina-api.json` and comes in two layouts, chosen at export: **multi-skill** (a generated `skills/melfina-<tool>/SKILL.md` per tool) or **single skill + script** (the umbrella `skills/melfina/SKILL.md` plus `scripts/melfina-call.mjs`, a zero-dependency Node shim agents run as `node scripts/melfina-call.mjs call <tool> '<args>'` — it reads the manifest, validates args, retries throttled calls, and prints actionable errors). Each skill mandates MCP-first, forbids calling both paths for one operation, and reads its credential from `$MELFINA_API_KEY` — nothing secret is baked into artifacts.

## What Melfina is not

- **A standalone ImpPost service** — messages are a bounded module inside Melfina, not a separate product. No channels, broadcast, presence, or human chat. Messages never grant access to another agent's private tree and are never auto-ingested into memory.
- **Mem0 / OpenMemory / Portable Memory (`.mem`)** — not a vector shared-memory layer and not Slack-for-agents.
- **Melina** — homophone; forbidden in copy.
- Waifu chat, character-on-a-background, purple Inter SaaS.

## Chrome

`Agents` · `Files` · `Shared` · `Instructions` · messages screen (label configurable, default `AgentPost`)

Owner auth, agent CRUD, VFS, MCP, Shared ACL, and that chrome are implemented — extend them in place; keep the modular monolith.

## Index

| Doc | What it locks |
| --- | --- |
| [shape.md](shape.md) | Architecture, stack, data, security, the three collaboration primitives |
| [look.md](look.md) | Palette, type, mark, CSS rules |
| [market.md](market.md) | Positioning, locked Liter copy, discovery |
| [theme/](theme/README.md) | Token swatch for the locked palette |

Product name **Imp** stays as-is. **ImpPost** is retired as a product boundary — it survives only as an optional label for the owner messages screen.
