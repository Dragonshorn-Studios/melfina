<?php

namespace App\Mcp;

use App\Models\Agent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Builds and serves the "fake plugin repository" for an agent: a real,
 * dumb-HTTP-cloneable git repo whose package wires the install's MCP
 * endpoint to the credentials presented in the clone request's HTTP Basic
 * auth (never the logged request path).
 *
 *     …/x.git + Basic {persona}:{fleetKey}   → Bearer melfg_… + X-Melfina-Agent-Key: <name>
 *     …/x.git + Basic {whatever}:{agentKey}  → Bearer melf_… (direct persona)
 *     …/~melfina.git + Basic {whatever}:{fleetKey} → fleet-wide package; the
 *         generated mcp.json sends Bearer melfg_… and each imp names
 *         itself per call via the `name` argument
 *
 * The fleet form's persona header carries the agent's *name*, not its key —
 * the name is a non-secret id the agent knows from its own instructions.
 *
 * Repos live under storage/app/mcp-plugins/ and are rebuilt on each
 * clone's entrypoint request (info/refs / HEAD) — never fingerprint-
 * cached, so a stale bake can't linger; object fetches inside that clone
 * serve the dir the entrypoint built.
 */
class PluginRepo
{
    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly SkillDocs $skills,
    ) {}

    /**
     * Ensure a fresh repo exists for these credentials and return its path.
     * `$mode` is 'fleet' or 'direct'; `$credential` is the plaintext secret
     * embedded in the package. `$fresh` marks a clone entrypoint request
     * (info/refs, HEAD) — those rebuild; object fetches serve the existing
     * dir so a clone spanning many requests sees one consistent repo.
     */
    public function ensureBuilt(Agent $agent, string $mode, string $credential, bool $fresh = true, PackageVariant $variant = PackageVariant::Multi): string
    {
        $dir = $this->repoDir($agent, $mode, $variant);

        if (! $fresh && is_file($dir.'/info/refs')) {
            return $dir;
        }

        return Cache::lock('melfina-plugin-repo:'.$agent->id.':'.$mode.':'.$variant->value, 30)
            ->block(10, function () use ($agent, $mode, $credential, $dir, $variant) {
                $this->build($dir, $this->packageFiles($agent, $mode, $credential, $variant));

                return $dir;
            });
    }

    /**
     * The fleet-wide package: one clone URL for every agent on the install.
     * It carries the fleet key but no persona — each imp names itself per
     * tool call via the `name` argument (or an X-Melfina-Agent-Key header
     * when the client can set one).
     */
    public function ensureGlobal(string $fleetKey, bool $fresh = true, PackageVariant $variant = PackageVariant::Multi): string
    {
        $dir = $this->globalDir($variant);

        if (! $fresh && is_file($dir.'/info/refs')) {
            return $dir;
        }

        return Cache::lock('melfina-plugin-repo:global:'.$variant->value, 30)
            ->block(10, function () use ($dir, $fleetKey, $variant) {
                $this->build($dir, $this->globalPackageFiles($fleetKey, $variant));

                return $dir;
            });
    }

    public function globalDir(PackageVariant $variant = PackageVariant::Multi): string
    {
        return storage_path('app/mcp-plugins/fleet'.$variant->repoMarker().'.git');
    }

    /**
     * The public clone name for the credential-free fleet package: derived
     * from APP_KEY (not any served credential), stable across fleet-key
     * rotations, and effectively unguessable — so the repo can be served
     * without a Basic challenge for importers that never retry with
     * credentials. Nothing inside the package is secret.
     */
    public function publicRepoName(PackageVariant $variant = PackageVariant::Multi): string
    {
        return 'g-'.substr(hash('sha256', 'melfina-public-repo|'.config('app.key')), 0, 32)
            .$variant->repoMarker().'.git';
    }

    /**
     * Deliberately lacks the `.git` suffix so `dropAllRepos` (which wipes
     * `*.git*` on credential rotation) leaves it alone — the public
     * package contains no credentials, so rotation doesn't stale it.
     */
    public function publicDir(PackageVariant $variant = PackageVariant::Multi): string
    {
        return storage_path('app/mcp-plugins/public-repo'.$variant->repoMarker());
    }

    /**
     * Build the public, credential-free fleet package (every sensitive
     * value in mcp.json is a placeholder the installer fills in) and
     * return its repo path.
     */
    public function ensureGlobalPublic(bool $fresh = true, PackageVariant $variant = PackageVariant::Multi): string
    {
        $dir = $this->publicDir($variant);

        if (! $fresh && is_file($dir.'/info/refs')) {
            return $dir;
        }

        return Cache::lock('melfina-plugin-repo:global-public:'.$variant->value, 30)
            ->block(10, function () use ($dir, $variant) {
                $this->build($dir, $this->publicPackageFiles($variant));

                return $dir;
            });
    }

    /**
     * Zip the credential-free fleet package for the requested layout.
     * Nothing inside is a credential, so the archive is built straight
     * from the generated file set — no on-disk staging dir to go stale.
     */
    public function publicPackageZip(PackageVariant $variant = PackageVariant::Multi): string
    {
        return Cache::lock('melfina-plugin-repo:public-pkg', 30)
            ->block(10, fn () => $this->zipFiles($this->publicPackageFiles($variant)));
    }

    /**
     * Zip an agent's plugin package for download. The credential-bearing
     * mcp.json/.mcp.json come from the persisted package dir (they hold
     * the plaintext key); every generated file — manifests, umbrella
     * skill, per-tool skills or the call script, melfina-api.json — is
     * rebuilt fresh for the requested layout, so a stale dir can't ship
     * an old tool surface. Serialized per agent so a concurrent key
     * rotation can't read a mid-rebuild credential.
     */
    public function packageZip(Agent $agent, PackageVariant $variant = PackageVariant::Multi): string
    {
        $dir = $this->packageDir($agent);

        return Cache::lock('melfina-plugin-repo:'.$agent->id.':pkg', 30)
            ->block(10, function () use ($agent, $dir, $variant): string {
                $files = [];

                foreach (['mcp.json', '.mcp.json'] as $credentialFile) {
                    $contents = @file_get_contents($dir.'/'.$credentialFile);
                    throw_if($contents === false, RuntimeException::class, "Cannot read package file {$credentialFile}");
                    $files[$credentialFile] = $contents;
                }

                return $this->zipFiles(array_merge($files, $this->packageDocs($agent, $variant)));
            });
    }

    /**
     * Resolve a repo-relative path (info/refs, HEAD, objects/…) to a real
     * file inside the built repo, or null when it escapes or does not exist.
     */
    public function file(string $dir, string $path): ?string
    {
        $base = realpath($dir);

        if ($base === false) {
            return null;
        }

        $full = realpath($base.'/'.ltrim($path, '/'));

        // Require the base's trailing slash — a bare prefix compare would
        // also match sibling files like `{id}.git.old-*` trash dirs.
        if ($full === false || ! str_starts_with($full, $base.'/') || ! is_file($full)) {
            return null;
        }

        return $full;
    }

    /**
     * The single entry point for keeping an agent's derived plugin artifacts
     * (clone repos + ZIP package) in step with credential/persona changes.
     * With a plaintext key in hand the package is rebuilt; without one —
     * rename, disable — every artifact is dropped because it embeds stale
     * credentials and cannot be regenerated.
     */
    public function syncForAgent(Agent $agent, ?string $plaintext = null): void
    {
        $this->dropRepo($agent);

        // Same lock packageZip holds while reading credentials +
        // zipping, so a download can't read a mid-rewrite package tree.
        Cache::lock('melfina-plugin-repo:'.$agent->id.':pkg', 30)
            ->block(10, function () use ($agent, $plaintext): void {
                if ($plaintext !== null) {
                    $this->rebuildPackage($agent, $plaintext);
                } else {
                    $this->dropPackage($agent);
                }
            });
    }

    /**
     * Regenerate the flat package directory used for ZIP downloads. Called
     * via syncForAgent when a plaintext agent key is in hand (create /
     * rotate).
     */
    public function rebuildPackage(Agent $agent, string $agentKey): void
    {
        $dir = $this->packageDir($agent);
        File::deleteDirectory($dir);

        foreach ($this->packageFiles($agent, 'direct', $agentKey) as $path => $contents) {
            $full = $dir.'/'.$path;
            File::ensureDirectoryExists(dirname($full));
            throw_unless(file_put_contents($full, $contents) !== false, RuntimeException::class, "Cannot write package file {$path}");
        }

        // These trees embed live credentials — keep them owner-only.
        chmod($dir, 0700);
    }

    /**
     * Zip a path→contents map into a 0600 temp file — the caller streams
     * it via response()->download()->deleteFileAfterSend(). tempnam's
     * file is already 0600; OVERWRITE truncates rather than replacing
     * it, so the credential-bearing archive never exists at a
     * world-readable path.
     *
     * @param  array<string, string>  $files
     */
    private function zipFiles(array $files): string
    {
        throw_unless(class_exists(\ZipArchive::class), RuntimeException::class, 'PHP ext-zip is not installed');

        $zipPath = tempnam(sys_get_temp_dir(), 'melfina-plugin-');
        $zip = new \ZipArchive;
        throw_unless($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true, RuntimeException::class);

        foreach ($files as $path => $contents) {
            throw_unless($zip->addFromString($path, $contents), RuntimeException::class, 'Cannot add '.$path.' to the package zip');
        }

        throw_unless($zip->close(), RuntimeException::class);
        chmod($zipPath, 0600);

        return $zipPath;
    }

    /**
     * Drop the cached repos for an agent — their packs embed plaintext
     * credentials (and the persona name), so stale copies are deleted on
     * rotation and rename rather than left on disk.
     */
    public function dropRepo(Agent $agent): void
    {
        foreach (glob(storage_path('app/mcp-plugins/'.$agent->id.'.*.git*')) ?: [] as $path) {
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
        }
    }

    /**
     * Drop every cached repo — the fleet key lives inside each pack, so a
     * fleet rotation wipes all of them.
     */
    public function dropAllRepos(): void
    {
        foreach (glob(storage_path('app/mcp-plugins/*.git*')) ?: [] as $path) {
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
        }
    }

    /**
     * Drop the flat ZIP package — done on rename, since rebuilding it needs
     * a plaintext key we only hold at create/rotate time.
     */
    public function dropPackage(Agent $agent): void
    {
        File::deleteDirectory($this->packageDir($agent));
    }

    public function packageDir(Agent $agent): string
    {
        return storage_path('app/mcp-plugins/'.$agent->id.'.pkg');
    }

    public function repoDir(Agent $agent, string $mode, PackageVariant $variant = PackageVariant::Multi): string
    {
        return storage_path('app/mcp-plugins/'.$agent->id.'.'.$mode.$variant->repoMarker().'.git');
    }

    /**
     * The MCP endpoint baked into packages — pinned to config('app.url')
     * rather than url(), which reflects the request's Host/X-Forwarded-Host
     * and would let any credential holder invalidate the cache at will.
     */
    private function endpoint(): string
    {
        return $this->baseUrl().'/mcp';
    }

    /**
     * The install's public origin — base for the /api/tools endpoints the
     * generated HTTP fallback skills document. Pinned to config for the
     * same reason as endpoint().
     */
    private function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * The generated HTTP-fallback skill files (one SKILL.md per tool under
     * skills/, plus melfina-api.json) shared by every package variant.
     *
     * @return array<string, string>
     */
    private function skillFiles(PackageVariant $variant = PackageVariant::Multi): array
    {
        return $this->skills->files($this->baseUrl(), $variant);
    }

    /**
     * @param  array<string, string>  $files  repo-root-relative path → contents
     */
    private function build(string $dir, array $files): void
    {
        // Temporary debugging for the stale-endpoint issue: record exactly
        // which URL source the serving process resolved.
        Log::info('plugin repo build', [
            'dir' => basename($dir),
            'config_app_url' => config('app.url'),
            'env_app_url' => env('APP_URL'),
            'request_host' => request()?->getHost(),
            'config_cached' => app()->configurationIsCached(),
            'endpoint' => $this->endpoint(),
        ]);

        $work = storage_path('app/mcp-plugins/build-'.Str::random(12));
        File::ensureDirectoryExists($work);
        chmod($work, 0700);

        foreach ($files as $path => $contents) {
            $full = $work.'/'.$path;
            File::ensureDirectoryExists(dirname($full));
            throw_unless(file_put_contents($full, $contents) !== false, RuntimeException::class, "Cannot write package file {$path}");
        }

        $steps = [
            ['-C', $work, 'init', '-b', 'main'],
            ['-C', $work, '-c', 'user.name=Melfina', '-c', 'user.email=melfina@localhost', 'add', '-A'],
            ['-C', $work, '-c', 'user.name=Melfina', '-c', 'user.email=melfina@localhost', 'commit', '-m', 'melfina plugin'],
            ['-C', $work, 'repack', '-ad'],
            ['-C', $work, 'update-server-info'],
        ];

        // Fixed timestamps keep every rebuild byte-identical (same commit
        // SHA, same pack names) — a clone spanning several requests sees
        // one consistent repo even though each request rebuilds it.
        $env = [
            'GIT_AUTHOR_DATE' => '2026-01-01T00:00:00Z',
            'GIT_COMMITTER_DATE' => '2026-01-01T00:00:00Z',
        ];

        foreach ($steps as $args) {
            $process = new Process(array_merge(['git'], $args), null, $env);
            $process->run();

            if (! $process->isSuccessful()) {
                File::deleteDirectory($work);

                Log::error('melfina plugin repo build failed', ['stderr' => $process->getErrorOutput()]);

                throw new RuntimeException('plugin repo build failed');
            }
        }

        File::ensureDirectoryExists(dirname($dir));

        // Swap via rename: readers never see a missing directory between
        // the old dir moving aside and the new one landing.
        $trash = $dir.'.old-'.Str::random(8);
        if (is_dir($dir)) {
            rename($dir, $trash);
        }
        rename($work.'/.git', $dir);
        chmod($dir, 0700);
        File::deleteDirectory($trash);
        File::deleteDirectory($work);
    }

    /**
     * Repo-root-relative path → contents for the whole plugin package. Ships
     * manifests for MiniMax (.minimax-plugin), the Agent Plugins community
     * layout (plugin.json), and Claude (.claude-plugin), all pointing at the
     * same mcp.json + skill.
     *
     * @return array<string, string>
     */
    private function packageFiles(Agent $agent, string $mode, string $credential, PackageVariant $variant = PackageVariant::Multi): array
    {
        $endpoint = $this->endpoint();

        $headers = $mode === 'fleet'
            ? [
                'Authorization' => 'Bearer '.$credential,
                'X-Melfina-Agent-Key' => $agent->name,
            ]
            : ['Authorization' => 'Bearer '.$credential];

        $mcp = json_encode([
            '$schema' => 'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json',
            'mcpServers' => [
                'melfina' => [
                    'type' => 'streamable-http',
                    'url' => $endpoint,
                    'headers' => $headers,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        return [
            'mcp.json' => $mcp,
            '.mcp.json' => $mcp,
            ...$this->packageDocs($agent, $variant),
        ];
    }

    /**
     * The credential-free half of an agent's package: manifests, docs,
     * and generated skills — everything except mcp.json, which carries
     * the key. Regenerable without the plaintext, so packageZip builds
     * this half fresh for every download in the requested layout.
     *
     * @return array<string, string>
     */
    private function packageDocs(Agent $agent, PackageVariant $variant = PackageVariant::Multi): array
    {
        $endpoint = $this->endpoint();
        $slug = Str::slug($agent->name) ?: 'agent';
        $name = 'melfina-'.$slug;

        $description = "Melfina brain for {$agent->name} — private files, instructions, and shared spaces.";

        $minimax = json_encode([
            'schemaVersion' => 1,
            'name' => $name,
            'displayName' => 'Melfina — '.$agent->name,
            'version' => '1.0.0',
            'description' => $description,
            'author' => 'Melfina',
            'category' => 'Productivity',
            'mcpServers' => ['mcp.json'],
            'skills' => ['skills/melfina/SKILL.md', ...$this->skills->skillPaths($variant)],
            'apps' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $agentPlugins = json_encode([
            '$schema' => 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json',
            'name' => $name,
            'version' => '1.0.0',
            'description' => $description,
            'author' => ['name' => 'Melfina'],
            'repository' => url('/'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $claude = json_encode([
            'name' => $name,
            'version' => '1.0.0',
            'description' => $description,
            'author' => ['name' => 'Melfina'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $readme = <<<MD
# melfina-{$slug}

Generated by your Melfina install for **{$agent->name}**. It carries live
credentials — treat the repo URL and these files like a password.

The \`mcp.json\` wires the \`melfina\` MCP server to {$endpoint}.
MD;

        $license = "Proprietary. Generated by a private Melfina install for the named agent only.\n";

        return [
            '.minimax-plugin/plugin.json' => $minimax,
            'plugin.json' => $agentPlugins,
            '.claude-plugin/plugin.json' => $claude,
            'skills/melfina/SKILL.md' => $this->umbrellaSkill($agent, $variant),
            'README.md' => $readme."\n",
            'LICENSE' => $license,
            ...$this->skillFiles($variant),
        ];
    }

    /**
     * The umbrella skills/melfina/SKILL.md shipped in the agent's
     * dedicated package — rendered here (not read back from storage) so
     * the console preview shows exactly what a package build embeds.
     */
    public function umbrellaSkill(Agent $agent, PackageVariant $variant = PackageVariant::Multi): string
    {
        $skill = <<<MD
---
name: melfina
description: Read and write this agent's private Melfina brain — its file tree, layered instructions, and shared spaces.
---

You are {$agent->name}. This plugin connects you to your own Melfina brain.

- `read_instructions` — your layered instructions; read them when you need your standing orders.
- `list_files` / `read_file` / `write_file` — your private tree. Nobody else can see or touch it.
- `list_shared` / `read_shared` / `write_shared` — shared spaces you hold an ACL grant on.
- `knowledge_lookup` / `knowledge_search` / `knowledge_upsert` / `knowledge_connect` — the shared internal knowledge graph; check it before any external search, and write typed links with the event that justified them.

Your persona comes from your name wired into this plugin's MCP headers —
the fleet key only opens the endpoint. Never paste the plugin URL or the
fleet key into chat or code.
MD;

        return $skill.$this->skills->appendix($this->baseUrl(), $variant, credentialEmbedded: true)."\n";
    }

    /**
     * The umbrella skills/melfina/SKILL.md shipped in the fleet-wide
     * package — persona-less: the key only opens the endpoint and each
     * imp names itself per call. Rendered here (not read back from
     * storage) so the console preview shows exactly what a build embeds.
     */
    public function fleetUmbrellaSkill(PackageVariant $variant = PackageVariant::Multi): string
    {
        $skill = <<<'MD'
---
name: melfina
description: Read and write your private Melfina brain — your file tree, layered instructions, and shared spaces.
---

This plugin connects you to your own Melfina brain. One copy serves every
agent on the install — the fleet key only opens the endpoint; it proves
membership, not identity. Claim your persona by passing your agent name as
the `name` argument on every tool call — every tool accepts it, and your
name is a non-secret id you know from your own instructions.

If your client can set headers, a fixed `X-Melfina-Agent-Key: <your name>`
header works instead of the per-call argument. URL-only clients can pin
the persona as the Basic username — `https://NAME:<fleet key>@host/mcp`,
percent-encode `:` or `@` (`ops:pilot` → `ops%3Apilot`).

- `read_instructions` — your layered instructions; read them when you need your standing orders.
- `list_files` / `read_file` / `write_file` — your private tree. Nobody else can see or touch it.
- `list_shared` / `read_shared` / `write_shared` — shared spaces you hold an ACL grant on.
- `knowledge_lookup` / `knowledge_search` / `knowledge_upsert` / `knowledge_connect` — the shared internal knowledge graph; check it before any external search, and write typed links with the event that justified them.

Never paste the plugin URL or the fleet key into chat or code.
MD;

        return $skill.$this->skills->appendix($this->baseUrl(), $variant, credentialEmbedded: true)."\n";
    }

    /**
     * Repo-root-relative path → contents for the fleet-wide package. One
     * install covers every agent: the MCP config sends the fleet key as a
     * Bearer credential and nothing else — discovery works persona-less,
     * and each imp claims its persona per tool call with the `name`
     * argument (or an X-Melfina-Agent-Key header when the client can set
     * one), which is how it scopes to its own brain on the shared endpoint.
     *
     * @return array<string, string>
     */
    private function globalPackageFiles(string $fleetKey, PackageVariant $variant = PackageVariant::Multi): array
    {
        $endpoint = $this->endpoint();

        $mcp = json_encode([
            '$schema' => 'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json',
            'mcpServers' => [
                'melfina' => [
                    'type' => 'streamable-http',
                    'url' => $endpoint,
                    'headers' => [
                        'Authorization' => 'Bearer '.$fleetKey,
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $readme = <<<MD
# melfina

Generated by a private Melfina install — the **fleet package**: one plugin
covers every agent. It carries the fleet key, so treat the repo URL and
these files like a password.

The `mcp.json` wires the `melfina` server to {$endpoint} with the fleet
key as a Bearer credential — nothing else to fill in. Each agent claims
its persona per call: it passes its own name (a non-secret id) as the
`name` argument on every tool call, or sets an
`X-Melfina-Agent-Key: <agent name>` header when the client supports
headers. Discovery (initialize, tools/list) needs no persona at all.

The endpoint is {$endpoint}.
MD;

        return array_merge($this->fleetManifestFiles($variant), [
            'mcp.json' => $mcp,
            '.mcp.json' => $mcp,
            'skills/melfina/SKILL.md' => $this->fleetUmbrellaSkill($variant),
            'README.md' => $readme."\n",
        ], $this->skillFiles($variant));
    }

    /**
     * Repo-root-relative path → contents for the public fleet package.
     * Deliberately credential-free — this repo is served without auth at
     * an unguessable URL, so the only placeholder is `YOUR_KEY` (the
     * agent's own `melf_…` key, or the fleet key) in the Bearer header.
     *
     * @return array<string, string>
     */
    public function publicPackageFiles(PackageVariant $variant = PackageVariant::Multi): array
    {
        $endpoint = $this->endpoint();

        $mcp = json_encode([
            '$schema' => 'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json',
            'mcpServers' => [
                'melfina' => [
                    'type' => 'streamable-http',
                    'url' => $endpoint,
                    'headers' => [
                        'Authorization' => 'Bearer YOUR_KEY',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $skill = <<<'MD'
---
name: melfina
description: Read and write your private Melfina brain — your file tree, layered instructions, and shared spaces.
---

This plugin connects you to your own Melfina brain. One copy serves every
agent on the install — before connecting, edit `mcp.json`: replace
`YOUR_KEY` in the Authorization header with your own `melf_…` key, or with
the install's fleet key (`melfg_…`) when the owner handed you that instead.

A dedicated `melf_…` key carries your persona already. On a fleet key the
key only opens the endpoint — claim your persona by passing your agent
name (a non-secret id you know from your own instructions) as the `name`
argument on every tool call, or set a fixed `X-Melfina-Agent-Key: <your
name>` header when your client supports headers. URL-only clients can pin
it as the Basic username — `https://NAME:<key>@host/mcp`, percent-encode
`:` or `@` (`ops:pilot` → `ops%3Apilot`).

- `read_instructions` — your layered instructions; read them when you need your standing orders.
- `list_files` / `read_file` / `write_file` — your private tree. Nobody else can see or touch it.
- `list_shared` / `read_shared` / `write_shared` — shared spaces you hold an ACL grant on.
- `knowledge_lookup` / `knowledge_search` / `knowledge_upsert` / `knowledge_connect` — the shared internal knowledge graph; check it before any external search, and write typed links with the event that justified them.

Never paste your key or a filled-in mcp.json into chat or code.
MD;

        $readme = <<<MD
# melfina

Generated by a private Melfina install — the **fleet package**: one plugin
covers every agent. This repo is public but unlisted and contains no
secrets — the only credential in it is a placeholder.

Before an agent connects, it edits `mcp.json`:

    "url": "{$endpoint}",
    "headers": { "Authorization": "Bearer YOUR_KEY" }

`YOUR_KEY` → the agent's own `melf_…` key (persona included), or the
install's fleet key (`melfg_…`) for shared setups — a fleet Bearer works
for discovery on its own, and the agent names itself per call with the
`name` argument or an `X-Melfina-Agent-Key: <agent name>` header.

The endpoint is {$endpoint}.
MD;

        return array_merge($this->fleetManifestFiles($variant), [
            'mcp.json' => $mcp,
            '.mcp.json' => $mcp,
            'skills/melfina/SKILL.md' => $skill.$this->skills->appendix($this->baseUrl(), $variant)."\n",
            'README.md' => $readme."\n",
        ], $this->skillFiles($variant));
    }

    /**
     * The manifest files identical across every fleet package variant
     * (Basic-gated and public) — the per-variant files (mcp.json, SKILL,
     * README) carry the credential wiring.
     *
     * @return array<string, string>
     */
    private function fleetManifestFiles(PackageVariant $variant = PackageVariant::Multi): array
    {
        $description = 'Melfina brain — private files, instructions, and shared spaces for every agent on this install.';

        return [
            '.minimax-plugin/plugin.json' => json_encode([
                'schemaVersion' => 1,
                'name' => 'melfina',
                'displayName' => 'Melfina — fleet',
                'version' => '1.0.0',
                'description' => $description,
                'author' => 'Melfina',
                'category' => 'Productivity',
                'mcpServers' => ['mcp.json'],
                'skills' => ['skills/melfina/SKILL.md', ...$this->skills->skillPaths($variant)],
                'apps' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            'plugin.json' => json_encode([
                '$schema' => 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json',
                'name' => 'melfina',
                'version' => '1.0.0',
                'description' => $description,
                'author' => ['name' => 'Melfina'],
                'repository' => url('/'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            '.claude-plugin/plugin.json' => json_encode([
                'name' => 'melfina',
                'version' => '1.0.0',
                'description' => $description,
                'author' => ['name' => 'Melfina'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            'LICENSE' => "Proprietary. Generated by a private Melfina install for its agents only.\n",
        ];
    }
}
