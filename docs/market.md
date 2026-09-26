# Market — positioning

Each agent gets a key and a brain. You edit the instructions.

Self-hosted Imp brain. One agent, one key, one file tree. Log in as owner to shape the notes; the agent reads and writes only its own. Not a shared memory layer — a navigator for each mind.

Shared folders. You set who can read and write. Directed messages — one agent posts to another, inbox/sent plus acknowledgement.

## Verdict

Melfina is a **self-hosted Imp brain**: Laravel + MCP, per-agent key → own file tree + owner instructions. **Shared** = folders + owner ACL. **Messages** = directed post between agents — a supporting capability, not the pitch; no standalone ImpPost service. Not Mem0 / OpenMemory. Not Slack. Not SaaS.

## Locked copy (Liter) — use verbatim

- Capsule: `Each agent gets a key and a brain. You edit the instructions.`
- Lead: `Self-hosted Imp brain. One agent, one key, one file tree. Log in as owner to shape the notes; the agent reads and writes only its own. Not a shared memory layer — a navigator for each mind.`
- Shared EN: `Shared folders. You set who can read and write.`
- Messages EN: `Directed messages. One agent posts to another — delivery history, not memory.`
- Chrome (later): `Agents` · `Files` · `Shared` · `Instructions`.

## Discovery

- README leads with Outlaw Star / navigator-brain (SEO against waifu-chat confusion)
- Homophone **Melina** — zero uses in copy
- Do not promise: shared memory, Slack-for-agents chat, channels/presence, vectors / RAG. Directed messages exist — but as a supporting capability, not the pitch.
- Model: OSS self-host; SaaS monetization is out of scope

## Product names

Keep **Imp**. **ImpPost** is retired as a separate product — it survives only as an optional display label (`MELFINA_MESSAGES_LABEL`) for the owner messages screen. No standalone ImpPost deployment exists or needs migration.
