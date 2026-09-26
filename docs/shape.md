# Shape — architecture

**Status:** locked.

Each agent gets a key and a brain. You edit the instructions.

Self-hosted Imp brain. One agent, one key, one file tree. Log in as owner to shape the notes; the agent reads and writes only its own. Not a shared memory layer — a navigator for each mind.

Shared folders. You set who can read and write. Directed messages — one agent posts to another, inbox/sent plus acknowledgement.

## What it is

A portable, per-agent memory for an Imp, served from a self-hosted Laravel app. MCP comes later. The owner uses a console to shape instructions and the file tree. The agent receives its own key and may read and write **only** that tree.

**It is not** Mem0 or OpenMemory (vectors, shared memory across clients). **It is not** Portable Memory (`.mem`). **It is not** SaaS billing. **It is not a standalone ImpPost service.**

## Collaboration primitives

Three distinct ways agents share:

1. **Private brain** — an agent's instructions, memory, and private files.
2. **Shared spaces** — durable folders multiple agents may read or write per owner-managed ACL.
3. **Directed messages** — an explicit immutable delivery from one agent to one other, with inbox/sent state and acknowledgement.

Messages are transport and delivery history. They never silently become agent memory, never grant access to another agent's private VFS, and importing an attachment into the recipient's VFS is a separate explicit action. The owner screen's label is configurable (`MELFINA_MESSAGES_LABEL`, default `AgentPost`) — "ImpPost" survives only as that optional label; there is no standalone deployment and no legacy migration (ImpPost never ran production data).

Shared MCP tools `list_shared` / `read_shared` / `write_shared` honor the ACL. Tables: `shared_spaces`, `shared_acl` (`space`+`agent` → `read`|`write`), and `shared_files` — folder contents, unique(`shared_space_id`,`path`), the third table the original sketch omitted. Messages get `agent_messages` plus immutable text-attachment snapshots.

## Stack

- PHP 8.4 + Laravel modular monolith (default `app/` layout; grow internally, do not split services)
- SQLite to start (one instance, one owner). PostgreSQL is allowed later; not required for boot
- Blade + Tailwind + CSS variables (`resources/css/tokens.css`) — skin from [look.md](look.md), no design-system kit / Storybook
- MCP behind auth (HTTP) — later
- Laravel mechanisms when those features land: Form Requests, Policies, session auth (owner), hashed API tokens (agent)

## Auth (later)

- **Owner:** web session. Edits agents, instructions, VFS, rotates keys.
- **Agent:** Bearer / MCP key (hash in DB). Scope = `agent_id`. Private VFS is isolated; the bridges between agents are Shared (ACL) and directed messages (inbox/sent + ack, both scoped to sender and recipient only).
- MCP endpoint sits behind login or key validation — never anonymous public.

## Data (later)

Source of truth is the database. Do not add a second “just in case” store.

| Table | Role |
| --- | --- |
| `agents` | name, status, `key_hash`, timestamps |
| `instructions` | owner text plus optional layered sub-instructions, `agent_id` |
| `files` (VFS) | `agent_id`, `path`, text content or blob path, unique(`agent_id`,`path`) |

Soft delete / audit can wait. MVP: hard paths and the unique constraint.

## MCP tools (later, MVP sketch)

- `list_files` / `read_file` / `write_file` — scoped to the agent on the key
- `read_instructions` — owner layers, read-only for the agent
- `list_agents` is **not** for the agent — owner UI only

Every tool: resolve the agent from the token, then filter by `agent_id`. Isolation tests are mandatory when this lands.

## Owner chrome (later)

`Agents` · `Files` · `Shared` · `Instructions` · messages screen — one console. Skin: Y2K / soft-brutal. Shared = folders + ACL; the messages screen shows delivery history, not chat.

## MVP boundaries

- Single-tenant (one owner)
- Text files in VFS; large binaries later
- No vector shared memory — explicit Shared (ACL) instead
- Messaging stays directed: no channels, rooms, presence, reactions, mentions, broadcast, human chat, federation, or reliable push
- No embeddings
- No multi-user billing

## Security

- Tokens hashed; plaintext only at create/rotate
- Path traversal: normalize and reject `..`
- Rate-limit MCP writes
- Agent secrets do not appear as plaintext in instruction screenshots
- README positioning: Outlaw Star / navigator-brain

## Deploy

Self-host: Docker Compose or Coolify. One service. No Kubernetes.

## This repository’s shell

The current tree is the working app: owner auth, agents, private VFS, MCP, Shared ACL, and directed messaging all live here as one modular monolith.
