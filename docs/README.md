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
