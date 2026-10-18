# Knowledge graph — proposal

**Status:** proposal. Not locked. Do not implement against this until the owner accepts it. When accepted, `shape.md` gains a fourth collaboration primitive (canonical world graph) and this file becomes the contract.

The shared knowledge base today is a wiki of named entries with an untyped `related` name list. Agents cannot connect the dots. This rework turns that base into a **knowledge graph**: typed entities, typed links with meaning, an append-only event log for every assertion, and **confidence** that rises when independent agents re-assert the same point or the owner states it directly.

This is still the install-wide canonical world-model. It is **not** Mem0, not embeddings, not per-agent memory, and not auto-ingest of mail. Private VFS stays the private brain. Messages stay transport.

## Why the current shape fails

`knowledge_entries.related` is `json` of canonical names. Lookup resolves those names to summaries and reports stale ones. That is a bag of "also see", not a graph:

- No **predicate**. `Yukine related Botbox` does not say runs-on, depends-on, or owned-by.
- No **direction**. Incoming vs outgoing is invisible.
- No **identity**. Links are strings; rename or typo becomes `unresolved_related`.
- No **confidence**. Owner fact and a single agent guess look the same.
- No **event**. The write that created the link is gone except as a whole-entry version snapshot. Agents cannot attach "what happened" to a connection.
- One agent overwriting `related` clobbers another agent's list — the optimistic `expected_version` lock is entry-wide, not link-wide.

The version table records wiki-body history. It does not record "grokbot asserted `Yukine runs_on Botbox` from deploy log X".

## Product placement

Collaboration primitives after this lands:

1. **Private brain** — instructions, memory files, private VFS. One agent.
2. **Shared spaces** — durable folders + owner ACL.
3. **Directed messages** — inbox/sent + ack. Transport, never memory.
4. **World graph** — named entities and typed links the whole install shares. Agents pull from it and write connections into it. The owner can confirm, retract, and edit.

Keep the market line: *not a shared memory layer*. The graph is a **canonical world-model**, the same role the knowledge base already has, with edges that mean something. Do not auto-ingest messages, VFS files, or shared-folder writes into the graph. An agent that wants a mail fact in the graph must call `knowledge_connect` (or the owner must write it).

No Neo4j, no embeddings, no vector index. SQLite + Eloquent. Recursive CTEs for bounded walks.

## Model

Three records. Entities are the nouns. Links are the typed edges. Observations are the events — the source of truth for confidence.

```
                  ┌──────────────┐
   knowledge_     │   Entity     │  wiki body (summary, state)
   entries        │  (node)      │  versioned as today
                  └──────┬───────┘
                         │ endpoints
                  ┌──────▼───────┐
   knowledge_     │    Link      │  predicate + cached confidence
   links          │  (edge)      │  unique (from, to, predicate)
                  └──────┬───────┘
                         │ evidenced by
                  ┌──────▼───────┐
   knowledge_     │ Observation  │  append-only event
   observations   │  (event)     │  author, payload, source
                  └──────────────┘
```

### Entity (evolve `knowledge_entries`)

Keep the current row. Canonical name, aliases, type, summary, state, source, `verified_at`, `updated_by`, `version`, version snapshots.

Drop `related` as a stored column after migration (see below). Neighborhood is queried from `knowledge_links`.

Add entity type `event` for a **named happening** (a deploy, an incident, a decision meeting). Existing types stay: `project`, `service`, `decision`, `convention`, `runbook`, `person`, `concept`.

Do not add org/place/artifact in v1. Aliases stay on the entity (names it answers to). Do not model aliases as extra nodes.

Owner merge of duplicate entities (`same_as`) is out of v1 — flag in observations, fix by hand in the console.

### Link (new)

Directed, typed, unique per `(from_entry_id, to_entry_id, predicate)`.

| Column | Role |
| --- | --- |
| `from_entry_id`, `to_entry_id` | FK to entities, cascade-safe (block delete while links exist, or cascade observations and drop the edge — owner delete of an entity drops its incident edges) |
| `predicate` | Closed enum (below) |
| `status` | `active` \| `retracted` |
| `confidence` | Integer **0–1000** (milli-confidence). Derived, cached, never a free-form agent input |
| `observation_count` | Denormalized count of live observations |
| `first_seen_at`, `last_seen_at` | |
| `disputed` | True when live asserts and retracts from different authors coexist |

Store one direction. Inverse reads use a predicate map (`runs_on` ↔ `hosts`). Do not duplicate the row.

Deleting an entity: cascade its links and observations. The wiki-body version history already cascades today.

### Observation (new) — the event log

Append-only. Every graph mutation writes one. This is "the data for each event".

| Column | Role |
| --- | --- |
| `kind` | `entity_upsert` \| `link_assert` \| `link_retract` \| `owner_confirm` |
| `entry_id` | Entity touched (nullable for pure link rows; still set to `from` for asserts) |
| `link_id` | Link touched, if any |
| `author` | Agent name or `owner` |
| `author_kind` | `agent` \| `owner` |
| `payload` | JSON — the event data |
| `source` | Provenance string (doc, ticket, chat, deploy log) |
| `occurred_at` | When the real-world thing happened (optional; distinct from `created_at`) |
| `created_at` | When Melfina recorded it |

`payload` shape for a link assert:

```json
{
  "from": "Yukine",
  "to": "Botbox",
  "predicate": "runs_on",
  "note": "Saw it in the 1.2 deploy log",
  "data": { "version": "1.2", "host": "botbox" }
}
```

Named happenings: if the agent also passes an event name, upsert an `event` entity and link it (`Yukine --involved_in--> Yukine deploy 1.2`, `Yukine deploy 1.2 --involves--> Botbox`) in the same transaction. The observation payload stays on the primary link either way.

Entity upserts already write `knowledge_entry_versions`. They **also** write an `entity_upsert` observation so the event log is complete. Do not replace the version table — it is the wiki snapshot; observations are the graph audit.

## Predicates (closed enum)

Agents pick from this list. Do not accept free-text predicates in v1 (that is how the graph turns back into `related`).

| Predicate | Inverse | Meaning |
| --- | --- | --- |
| `related_to` | `related_to` | Legacy / weak "also see". Migration dump only; new writes prefer a sharper predicate |
| `depends_on` | `supports` | A needs B |
| `part_of` | `has_part` | Composition |
| `runs_on` | `hosts` | Service/project on a host/runtime |
| `owned_by` | `owns` | Stewardship |
| `uses` | `used_by` | Consumes |
| `decided_in` | `decides` | Decision entity ↔ subject |
| `involves` | `involved_in` | Event ↔ participant |
| `authored_by` | `authored` | Person wrote the thing |
| `contradicts` | `contradicts` | Explicit conflict between entities |
| `supersedes` | `superseded_by` | Replacement |

`supports` / `has_part` / `hosts` / … are **read-side inverses**. The stored predicate is always the left-hand column. An agent may write either name; the service canonicalizes (`hosts` with from/to swapped becomes `runs_on`).

## Confidence

Agents never pass a confidence number. Melfina derives it from observations. Integer 0–1000, clamp, no floats in SQLite.

### Formula (v1, inspectable)

Let

- `O` = count of **owner** `link_assert` or `owner_confirm` observations on this link that have not been owner-retracted
- `A` = count of **distinct agent names** with a net-positive assert (asserts minus their own retracts > 0)
- `R` = extra repeat asserts beyond the first per agent, capped at 3 per agent, summed
- `D` = distinct authors (agent or owner) with a live retract while asserts still exist

```
raw = 0
if O > 0:           raw += 900          # owner direct info
raw += 220 * A      # independent agents connecting the same dots
raw += 30 * R       # same agent saying it again, small bump, capped
if D > 0:           raw -= 250 * D      # dispute
                    disputed = true
confidence = clamp(raw, 0, 990)
if owner retracted the link: status = retracted, confidence = 0
if owner verified the link (`owner_confirm` or console verify): confidence = max(confidence, 950)
```

Worked numbers:

| Situation | Confidence |
| --- | --- |
| One agent asserts once | 220 |
| Same agent asserts three more times | 310 (220 + 90) |
| Two distinct agents assert | 440 |
| Owner writes or confirms | 900–950 |
| Owner confirms after agents | 950 |
| Two agents assert, one other retracts | 190 and `disputed` |
| Owner retracts | 0, `retracted` |

One noisy agent cannot max the edge. The owner always can. Re-asserts by the same agent help a little so a careful agent logging a second deploy is not a no-op, and cannot substitute for a second mind.

Recompute inside the same DB transaction as the observation insert. Cache on the link row so lookup does not scan the log. Tests lock the table above — change the formula only with a migration note and those tests.

Entity-level `verified_at` stays as today (wiki body freshly checked). Link confidence is separate. Do not conflate "the summary is right" with "this edge is right".

## Agent tools

Keep the noun path. Add one write tool for connections. Do not explode the surface.

| Tool | Scope | Change |
| --- | --- | --- |
| `knowledge_lookup` | `knowledge:read` | Return the entity **plus 1-hop neighborhood**: incoming/outgoing links with predicate, peer name/type/summary, confidence, disputed, last observation. Optional `hops` 1–2 (cap 25 nodes). Optional `min_confidence` (default 0, so weak links stay visible). `history` also returns recent observations. |
| `knowledge_search` | `knowledge:read` | Unchanged matching. Results may include `top_link` (highest-confidence neighbor) later; not required for v1. |
| `knowledge_upsert` | `knowledge:write` | Wiki body only. `related` accepted through one compatibility window: each name becomes a `related_to` assert. Then reject `related` with a hint to `knowledge_connect`. |
| `knowledge_connect` | `knowledge:write` | **New.** Assert or retract a typed link and attach event data. |

### `knowledge_connect`

```
from          string   required  canonical name or alias
to            string   required
predicate     enum     required
op            enum     assert (default) | retract
note          string   optional  why, one line
data          object   optional  event payload (flat JSON, size-capped)
source        string   optional  provenance
occurred_at   string   optional  ISO timestamp of the real-world event
event         string   optional  if set, also upsert an `event` entity with this name and involve both ends
```

Missing endpoint: create a stub entity (`type=concept`, summary empty, `updated_by` the writer) only when `create_missing=true`; otherwise return `unresolved` and do not write the link. Default **false** so agents cannot fill the graph with typos. The owner console can create-and-link in one form.

Same rate key as upsert (`mcp-knowledge:{agent_id}`, 30/min). Same author stamp (agent name / `owner`).

Return the link: predicate, from, to, confidence, status, observation id.

### Lookup payload (additive)

Existing fields stay. Add:

```json
{
  "links": {
    "outgoing": [
      {
        "predicate": "runs_on",
        "name": "Botbox",
        "type": "service",
        "summary": "home server",
        "confidence": 440,
        "disputed": false,
        "observations": 2
      }
    ],
    "incoming": []
  }
}
```

Unresolved name-only related rows from pre-migration data that never matched an entity are dropped at migrate time (they are already `unresolved_related` today). Do not keep a parallel JSON list.

### Instructions / skills / catalog

- `MelfinaServer` instructions: after lookup, follow typed links; write new connections with `knowledge_connect` and the event payload; do not overwrite wiki `state` with a link.
- `ToolCatalog`: `knowledge_connect` → `knowledge:write`, JSON tool. Generated skills and HTTP fallback come along for free.
- `PluginRepo` umbrella bullets: mention `knowledge_connect`.
- Fleet/server copy: internal nouns first still holds.

## Owner console

Keep list + wiki editor. On the entity page, replace the "related — one per line" textarea with a **neighborhood** panel:

- Group by predicate. Show confidence as `440` (or `0.44` display only — storage stays 0–1000).
- Owner actions per link: **confirm** (`owner_confirm`, jumps confidence) and **retract**.
- Add-link form: to, predicate, optional note/source. Author `owner` — this is "user gives them direct info".
- Observation log under version history (newest first, same details disclosure pattern).
- Filter list page by type still; optional later filter "disputed only".

No force-directed canvas in v1. Look.md's constellation mark is available later as a read-only SVG of the 1-hop neighborhood; not required to ship the graph. Blade + existing tokens.

Delete entity: confirm copy must say links and observations go with it.

## Schema sketch

SQLite. Laravel migrations. No new packages.

```
knowledge_links
  id
  from_entry_id  FK knowledge_entries
  to_entry_id    FK knowledge_entries
  predicate      string  (check in app; PHP backed enum)
  status         string  default 'active'
  confidence     unsignedInteger  default 0
  observation_count unsignedInteger default 0
  disputed       boolean default false
  first_seen_at  timestamp
  last_seen_at   timestamp
  timestamps
  unique (from_entry_id, to_entry_id, predicate)
  index (to_entry_id, predicate)   # incoming
  check from_entry_id != to_entry_id

knowledge_observations
  id
  kind           string
  entry_id       FK nullable
  link_id        FK nullable
  author         string
  author_kind    string
  payload        json
  source         string nullable
  occurred_at    timestamp nullable
  timestamps
  index (link_id, created_at)
  index (entry_id, created_at)
```

PHP enums (`KnowledgePredicate`, `KnowledgeObservationKind`, `KnowledgeLinkStatus`) — the codebase already uses backed enums (`PackageVariant`).

Service: `KnowledgeGraph` next to `MessageService`. Tools and the console controller call the service; they do not recompute confidence.

2-hop walk: recursive CTE, depth ≤ 2, node cap 25, skip `retracted`, honor `min_confidence`. Cycles: don't revisit a node.

## Migration of existing `related`

One data migration after the tables exist:

1. For each entry, for each name in `related` that `lookup()` resolves, `knowledge_connect`-equivalent assert `related_to` with author = that entry's `updated_by` (unknown → `owner`), source `migrated from related`.
2. Unresolved names: skip (they already failed at read). Count them in the migration log.
3. Drop `related` from `knowledge_entries` and from version snapshot shape going forward. Old version JSON may still contain `related`; leave historical snapshots untouched.
4. `knowledge_upsert` compatibility: accept `related` for one release, map to `related_to`, then reject.

Factories: drop `related`; add `KnowledgeLinkFactory` / `KnowledgeObservationFactory`.

## Tests (when implementing)

Feature tests, Pest, factories. Cover:

- Connect assert creates link + observation; lookup neighborhood includes it.
- Second distinct agent on the same predicate raises confidence per the table.
- Same agent repeats cap; cannot reach owner-level confidence alone.
- Owner confirm → ≥ 950; owner retract → 0 + `retracted`; lookup omits retracted by default (include with a flag if we add one).
- Inverse predicate canonicalizes (`hosts` writes `runs_on` swapped).
- Unresolved endpoint without `create_missing` writes nothing.
- `related` compatibility maps into `related_to`.
- Console confirm/retract as owner; non-owner 403.
- Rate limit shares the knowledge write key.
- Optimistic wiki `expected_version` still only guards **body** upsert, not connects (connects never bump entity `version`).
- Delete entity cascades links and observations.
- Scoped key: `knowledge:read` can lookup neighborhood, cannot connect; `knowledge:write` can.

Do not add a tinker/verification script.

## Implementation slices

Ship in this order so each slice is reviewable and leaves the install working:

1. **Schema + service + migrate `related`.** Lookup neighborhood from links. No new tool yet. Console still shows related names via the new query (read-only neighborhood).
2. **`knowledge_connect` + lookup payload + tests for confidence.** Compatibility on upsert `related`.
3. **Owner confirm/retract + observation log on the entity page.** Remove the related textarea.
4. **`event` type + optional `event` argument on connect.** 2-hop on lookup.
5. **Drop `related` column and upsert argument.** Update generated-skill copy, server instructions, `docs/shape.md` (fourth primitive), this file's status → locked.

Do not implement owner auth, agents CRUD, VFS, MCP plumbing, Shared ACL, or chrome beyond the knowledge screens this needs.

## Out of scope (v1)

- Embeddings, RAG, vector search
- Graph database, Cypher, SPARQL
- Auto-extracting entities from VFS / mail / shared files
- Per-agent private graphs (the private brain is still files)
- Free-text predicates, RDF, user-defined types
- Entity merge UI, temporal "valid at" intervals beyond `occurred_at` on an observation
- Force-directed / constellation visualization
- Confidence decay over time (revisit if stale edges become a problem)
- Broadcasting graph changes over mail ping

## Copy

Spell **Melfina**. Chrome tab stays `knowledge`. Do not call this ImpPost, Melina, Mem0, or "shared memory". Owner-facing eyebrow can stay `KNOWLEDGE`; subtitle becomes "Canonical entities, typed links" once slice 3 ships.
