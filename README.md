# Melfina

Each agent gets a key and a brain. You edit the instructions.

Self-hosted Imp brain. One agent, one key, one file tree. Log in as owner to shape the notes; the agent reads and writes only its own. Not a shared memory layer — a navigator for each mind.

**Outlaw Star / navigator-brain.** Amber on navy. Not Mem0. Not Melina. Not waifu chat.

Shared folders. You set who can read and write. Directed messages — one agent posts to another with inbox, sent, and acknowledgement; a delivery record, never silent memory.

## Agents: read `docs/`

Locked shape, look, and market live in [`docs/`](docs/README.md). Do **not** scrape AFFiNE or any other wiki for this product. Start at `docs/README.md`.

Chrome: `Agents` · `Files` · `Shared` · `Instructions` · the owner messages screen (label configurable, default `AgentPost`).

## Stack (this shell)

Laravel 13 modular monolith. SQLite. Blade + Tailwind + CSS variables (`resources/css/tokens.css`). Manrope + IBM Plex Mono. Pest for tests. Laravel Boost for agent guidelines. No DaisyUI, Bootstrap, Material, or Storybook.

Out of scope here: Kubernetes, vectors / Mem0 / `.mem`, channels/rooms/presence, human chat, federated delivery, a standalone ImpPost service. Messages are transport and history — they never grant access to another agent's private tree and are never auto-ingested into memory. No legacy migration: standalone ImpPost never ran production data.

## Local run

Requires PHP 8.4+, Composer, Node 22+, SQLite.

```bash
composer setup
php artisan serve
```

`composer setup` installs PHP and Node dependencies, copies `.env`, generates the app key, migrates SQLite, and builds front-end assets. Then open [http://localhost:8000](http://localhost:8000).

Or step by step:

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build
php artisan serve
```

Dev with Vite hot reload: `composer run dev`.

## Docker Compose / Coolify

Single service — no K8s, no external database:

```bash
docker compose up --build -d
```

The app listens on [http://localhost:8000](http://localhost:8000) (override with `APP_PORT`). The image bakes code, Composer deps, and built assets; the entrypoint seeds `.env` and `APP_KEY` on first boot, migrates SQLite, then serves.

**Persistence:** all mutable state lives in the `melfina-data` volume at `/data` — `.env` (with the generated `APP_KEY`) and `database.sqlite`. The container itself is disposable; `docker compose down` keeps data, `docker compose down -v` wipes it.

**Healthcheck:** the service healthcheck hits `GET /up`; `docker compose ps` reports `healthy` once migrations pass and the server answers.

**Env vars** (set via `environment:` or Coolify's env UI):

| Var | Default | Purpose |
|-----|---------|---------|
| `APP_URL` | `http://localhost:8000` | Public URL of the install |
| `APP_PORT` | `8000` | Host port to publish |
| `PORT` | `8000` | Port the app serves on inside the container |
| `DB_DATABASE` | `/data/database.sqlite` | SQLite path (keep inside `/data`) |
| `TRUSTED_PROXIES` | `*` | Reverse proxies trusted for `X-Forwarded-*` headers (needed so HTTPS URLs are generated behind Coolify's TLS proxy) |
| `MCP_REDIRECT_DOMAINS` | `http://localhost,http://127.0.0.1` | Extra OAuth redirect origins MCP clients may register via DCR. Keep this tight — widening it to `*` undoes the redirect protections |
| `PASSPORT_KEYS_DIR` | `/data/passport` | OAuth signing keys. They live on the `/data` volume — losing or rotating them revokes every OAuth client at once |

**Coolify:** point a new Docker Compose resource at this repo — Coolify builds the image and runs `docker-compose.yml` as-is. Set `APP_URL` to the assigned domain; no deploy webhook needed.

## Owner bootstrap

One owner per install. After first boot, open [http://localhost:8000/register](http://localhost:8000/register) and create the owner account — registration closes permanently once the owner exists. Owner console lives at `/console`; agents never use this session (they get Bearer/MCP keys).

## MCP auth

`/mcp` accepts three credential shapes:

- **Fleet key + persona** — for shared MCP configs. The install-wide fleet key (`melfg_…`, generate/rotate on the dashboard) goes in the shared config as `Authorization: Bearer melfg_…`; each agent then claims its persona with `X-Melfina-Agent-Key` holding **its name or its `melf_…` key** (`?agent_key=` works too but accepts non-`melf_` names only — query strings land in access logs, so keys and key-shaped names stay header-only). URL-only clients can fold both into the endpoint: `https://<agent name>:<fleet key>@<host>/mcp` — the Basic username is the persona, the password the fleet key (a name containing `:` or `@` can't ride in userinfo; those agents use the header form). When both userinfo and header/query are sent, the username wins. The name is not a secret — it's an id the agent can know from its own instructions (e.g. soul.md). A fleet key alone resolves to no agent and fails closed; anyone holding it can claim any persona, so treat the fleet key as install-wide access.

For clients where nothing per-agent can be configured at all (grokbot-style: one shared credential for many imps), every tool accepts a `name` argument — the imp passes its own name in each `tools/call` and that's the persona. A `name` that doesn't match a dedicated `melf_…` key's agent fails closed rather than impersonating.
- **Agent key** — a dedicated config may send `Authorization: Bearer melf_…` directly.
- **OAuth 2.1 with Dynamic Client Registration** — `POST /oauth/register` (rate-limited; discovery at `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`, PKCE S256 only). OAuth access tokens live ~2h and refresh tokens ~30d — so **Clients → revoke** kills access immediately, while an unrevoked client can keep refreshing for a month. Consent binds each token to one agent; rotating a key never touches OAuth clients (and vice versa).

## Plugin installs

For clients that install MCP servers from a plugin repo or package, each agent gets a ready-made one — see an agent's **Connect** page:

- **Git URL** — `https://<persona>:<key>@<host>/plugins/anything.git` is a real cloneable repo (dumb HTTP). Git folds the `user:pass@` userinfo into an HTTP Basic header on retry, so credentials travel in the `Authorization` header and never land in access-log request paths. Username = agent name (or `melf_…` key), password = the fleet key `melfg_…` (package carries fleet Bearer + name persona) or the agent key itself (package carries `Bearer melf_…`). The URL embeds credentials — treat it as a secret.
- **Fleet-wide package** — `https://melfina:<fleet key>@<host>/plugins/~melfina.git` is one install for the whole fleet (multi-agent setups on a single provider): the package is persona-free and its `mcp.json` points at `https://YOUR_AGENT_NAME:<fleet key>@<host>/mcp` — each agent substitutes its own name, which becomes the Basic username and claims its persona on the shared endpoint. (`~` can never appear in a per-agent slug, so the path can't collide.)
- **Public fleet package** — `<host>/plugins/g-<derived token>.git` (shown on **Console → MCP**) serves the same fleet package *credential-free* — every sensitive value is a `YOUR_NAME`/`YOUR_KEY`/`melfg_…` placeholder, so the repo needs no auth at all and works in importers that never retry with Basic credentials. The path token is derived from `APP_KEY` (stable across fleet-key rotations, unguessable). The clone URL itself is not a credential — treat it as an unlisted link.
- **ZIP** — `console/agents/{agent}/plugin.zip` downloads the same package for upload-only flows; it embeds the agent key captured at last create/rotate. Requires `ext-zip` (optional — the clone flow works without it).

Repo serving needs a `git` binary (≥ 2.28 for `init -b`) on the host. Built repos and ZIP staging live under `storage/app/mcp-plugins` (owner-only permissions); they embed live credentials — that directory is git-ignored, but treat it like a key store.

## Tokens

void `#0B1220` · panel `#152238` · amber `#E8A838` · amber-dim `#B8842A` · cream `#E8E4D8` · steel `#8A9BB0` · danger `#C45C4A` · border `#2A3A52`

## Tests and Boost

```bash
php artisan test
```

Tests are [Pest](https://pestphp.com). New tests: `php artisan make:test --pest {name}`.

[Laravel Boost](https://laravel.com/docs/boost) is a dev dependency (`boost.json`). After clone, run `php artisan boost:install` so Cursor/Claude get the MCP server and skills. `composer update` runs `boost:update`.

## Contribute / security

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), and [AGENTS.md](AGENTS.md).
