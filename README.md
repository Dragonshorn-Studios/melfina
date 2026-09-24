# Melfina

Each agent gets a key and a brain. You edit the instructions.

Self-hosted Imp brain. One agent, one key, one file tree. Log in as owner to shape the notes; the agent reads and writes only its own. Not a shared memory layer — a navigator for each mind.

**Outlaw Star / navigator-brain.** Amber on navy. Not Mem0. Not ImpPost-mail. Not Melina. Not waifu chat.

Shared folders. You set who can read and write. Not mail — that’s ImpPost.

## Agents: read `docs/`

Locked shape, look, and market live in [`docs/`](docs/README.md). Do **not** scrape AFFiNE or any other wiki for this product. Start at `docs/README.md`.

Chrome (later — mention only): `Agents` · `Files` · `Shared` · `Instructions`.

## Stack (this shell)

Laravel 13 modular monolith. SQLite. Blade + Tailwind + CSS variables (`resources/css/tokens.css`). Manrope + IBM Plex Mono. No DaisyUI, Bootstrap, Material, or Storybook.

Out of scope here: owner auth, agents CRUD, VFS, MCP, Shared ACL, full chrome UI, Kubernetes, ImpPost, vectors / Mem0 / `.mem`.

## Local run

Requires PHP 8.3+, Composer, Node 22+, SQLite.

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

## Docker Compose

```bash
docker compose up --build
```

The app listens on [http://localhost:8000](http://localhost:8000). First start installs Composer/npm deps and migrates SQLite inside the container.

## Tokens

void `#0B1220` · panel `#152238` · amber `#E8A838` · amber-dim `#B8842A` · cream `#E8E4D8` · steel `#8A9BB0` · danger `#C45C4A` · border `#2A3A52`

## Contribute / security

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), and [AGENTS.md](AGENTS.md).
