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
 *         agent fills its own name into the Basic-URL username of /mcp
 *
 * The fleet form's persona header carries the agent's *name*, not its key —
 * the name is a non-secret id the agent knows from its own instructions.
 *
 * Repos are rebuilt on every request under storage/app/mcp-plugins/ —
 * plugin downloads are rare enough that always-fresh beats cached
 * (a stale bake once shipped a localhost endpoint to agents).
 */
class PluginRepo
{
    /**
     * Ensure a fresh repo exists for these credentials and return its path.
     * `$mode` is 'fleet' or 'direct'; `$credential` is the plaintext secret
     * embedded in the package (fleet key or agent key).
     */
    public function ensureBuilt(Agent $agent, string $mode, string $credential): string
    {
        $dir = $this->repoDir($agent, $mode);

        // No caching: every request rebuilds. Plugin repos are fetched
        // rarely (once per agent install), so freshness beats speed.
        return Cache::lock('melfina-plugin-repo:'.$agent->id.':'.$mode, 30)
            ->block(10, function () use ($agent, $mode, $credential, $dir) {
                $this->build($dir, $this->packageFiles($agent, $mode, $credential));

                return $dir;
            });
    }

    /**
     * The fleet-wide package: one clone URL for every agent on the install.
     * It carries the fleet key but no persona — the agent (or whoever
     * installs it) substitutes its own name, which becomes the Basic
     * username on the /mcp URL.
     */
    public function ensureGlobal(string $fleetKey): string
    {
        $dir = $this->globalDir();

        return Cache::lock('melfina-plugin-repo:global', 30)
            ->block(10, function () use ($dir, $fleetKey) {
                $this->build($dir, $this->globalPackageFiles($fleetKey));

                return $dir;
            });
    }

    public function globalDir(): string
    {
        return storage_path('app/mcp-plugins/fleet.git');
    }

    /**
     * The public clone name for the credential-free fleet package: derived
     * from APP_KEY (not any served credential), stable across fleet-key
     * rotations, and effectively unguessable — so the repo can be served
     * without a Basic challenge for importers that never retry with
     * credentials. Nothing inside the package is secret.
     */
    public function publicRepoName(): string
    {
        return 'g-'.substr(hash('sha256', 'melfina-public-repo|'.config('app.key')), 0, 32).'.git';
    }

    /**
     * Deliberately lacks the `.git` suffix so `dropAllRepos` (which wipes
     * `*.git*` on credential rotation) leaves it alone — the public
     * package contains no credentials, so rotation doesn't stale it.
     */
    public function publicDir(): string
    {
        return storage_path('app/mcp-plugins/public-repo');
    }

    /**
     * Build the public, credential-free fleet package (every sensitive
     * value in mcp.json is a placeholder the installer fills in) and
     * return its repo path.
     */
    public function ensureGlobalPublic(): string
    {
        $dir = $this->publicDir();

        return Cache::lock('melfina-plugin-repo:global-public', 30)
            ->block(10, function () use ($dir) {
                $this->build($dir, $this->publicPackageFiles());

                return $dir;
            });
    }

    /**
     * Flat directory of the credential-free fleet package for ZIP
     * downloads (same layout as per-agent packages).
     */
    public function publicPackageDir(): string
    {
        return storage_path('app/mcp-plugins/public-pkg');
    }

    /**
     * Regenerate the flat public package directory. Safe to rebuild on
     * every download — nothing inside is a credential.
     */
    public function rebuildPublicPackage(): string
    {
        $dir = $this->publicPackageDir();
        File::deleteDirectory($dir);

        foreach ($this->publicPackageFiles() as $path => $contents) {
            $full = $dir.'/'.$path;
            File::ensureDirectoryExists(dirname($full));
            file_put_contents($full, $contents);
        }

        return $dir;
    }

    /**
     * Rebuild the public package and zip it under a lock — the zip reads
     * the shared directory at close() time, so concurrent downloads must
     * not see a mid-rebuild tree.
     */
    public function publicPackageZip(): string
    {
        return Cache::lock('melfina-plugin-repo:public-pkg', 30)
            ->block(10, fn () => $this->zipDirectory($this->rebuildPublicPackage()));
    }

    /**
     * Zip a package directory into a 0600 temp file and return its path —
     * the caller streams it via response()->download()->deleteFileAfterSend().
     * tempnam's file is already 0600; OVERWRITE truncates rather than
     * replacing it, so the credential-bearing archive never exists at
     * a world-readable path.
     */
    public function zipDirectory(string $dir): string
    {
        throw_unless(class_exists(\ZipArchive::class), RuntimeException::class, 'PHP ext-zip is not installed');

        $zipPath = tempnam(sys_get_temp_dir(), 'melfina-plugin-');
        $zip = new \ZipArchive;
        throw_unless($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true, RuntimeException::class);

        // allFiles with $hidden=true ships the dot-prefixed manifests
        // (.minimax-plugin/, .claude-plugin/, .mcp.json) — and needs no
        // GLOB_BRACE, which musl/Alpine PHP builds lack.
        foreach (File::allFiles($dir, hidden: true) as $file) {
            $zip->addFile($file->getPathname(), $file->getRelativePathname());
        }

        throw_unless($zip->close(), RuntimeException::class);
        chmod($zipPath, 0600);

        return $zipPath;
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

        if ($plaintext !== null) {
            $this->rebuildPackage($agent, $plaintext);
        } else {
            $this->dropPackage($agent);
        }
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
            file_put_contents($full, $contents);
        }

        // These trees embed live credentials — keep them owner-only.
        chmod($dir, 0700);
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

    public function repoDir(Agent $agent, string $mode): string
    {
        return storage_path('app/mcp-plugins/'.$agent->id.'.'.$mode.'.git');
    }

    /**
     * The MCP endpoint baked into packages — pinned to config('app.url')
     * rather than url(), which reflects the request's Host/X-Forwarded-Host
     * and would let any credential holder invalidate the cache at will.
     */
    private function endpoint(): string
    {
        return rtrim((string) config('app.url'), '/').'/mcp';
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
            file_put_contents($full, $contents);
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
    private function packageFiles(Agent $agent, string $mode, string $credential): array
    {
        $endpoint = $this->endpoint();
        $slug = Str::slug($agent->name) ?: 'agent';
        $name = 'melfina-'.$slug;

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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

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
            'skills' => ['skills/melfina/SKILL.md'],
            'apps' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $agentPlugins = json_encode([
            '$schema' => 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json',
            'name' => $name,
            'version' => '1.0.0',
            'description' => $description,
            'author' => ['name' => 'Melfina'],
            'repository' => url('/'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $claude = json_encode([
            'name' => $name,
            'version' => '1.0.0',
            'description' => $description,
            'author' => ['name' => 'Melfina'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $skill = <<<MD
---
name: melfina
description: Read and write this agent's private Melfina brain — its file tree, layered instructions, and shared spaces.
---

You are {$agent->name}. This plugin connects you to your own Melfina brain.

- `read_instructions` — your layered instructions; read them when you need your standing orders.
- `list_files` / `read_file` / `write_file` — your private tree. Nobody else can see or touch it.
- `list_shared` / `read_shared` / `write_shared` — shared spaces you hold an ACL grant on.

Your persona comes from your name wired into this plugin's MCP headers —
the fleet key only opens the endpoint. Never paste the plugin URL or the
fleet key into chat or code.
MD;

        $readme = <<<MD
# melfina-{$slug}

Generated by your Melfina install for **{$agent->name}**. It carries live
credentials — treat the repo URL and these files like a password.

The \`mcp.json\` wires the \`melfina\` MCP server to {$endpoint}.
MD;

        $license = "Proprietary. Generated by a private Melfina install for the named agent only.\n";

        return [
            'mcp.json' => $mcp,
            '.mcp.json' => $mcp,
            '.minimax-plugin/plugin.json' => $minimax,
            'plugin.json' => $agentPlugins,
            '.claude-plugin/plugin.json' => $claude,
            'skills/melfina/SKILL.md' => $skill."\n",
            'README.md' => $readme."\n",
            'LICENSE' => $license,
        ];
    }

    /**
     * Repo-root-relative path → contents for the fleet-wide package. One
     * install covers every agent: the MCP URL carries the fleet key as the
     * Basic password and `YOUR_AGENT_NAME` as the Basic username — each
     * agent substitutes its own name at install, which is how it claims
     * its persona on the shared endpoint.
     *
     * @return array<string, string>
     */
    private function globalPackageFiles(string $fleetKey): array
    {
        $endpoint = $this->endpoint();

        // https://host/mcp → https://YOUR_AGENT_NAME:{key}@host/mcp — the
        // username is the persona, the password the fleet credential.
        $personaUrl = preg_replace(
            '#^(https?://)#',
            '${1}YOUR_AGENT_NAME:'.rawurlencode($fleetKey).'@',
            $endpoint
        );

        $mcp = json_encode([
            '$schema' => 'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json',
            'mcpServers' => [
                'melfina' => [
                    'type' => 'streamable-http',
                    'url' => $personaUrl,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $skill = <<<'MD'
---
name: melfina
description: Read and write your private Melfina brain — your file tree, layered instructions, and shared spaces.
---

This plugin connects you to your own Melfina brain. One copy serves every
agent on the install — before connecting, replace `YOUR_AGENT_NAME` in the
`mcp.json` URL with your own agent name. That name is your persona: it is
not a secret, and you can find it in your own instructions.

If your name contains `:` or `@`, percent-encode it in the URL
(`ops:pilot` → `ops%3Apilot`) — or skip the URL form entirely and send
`Authorization: Bearer <fleet key>` plus `X-Melfina-Agent-Key: <your name>`
as headers instead.

- `read_instructions` — your layered instructions; read them when you need your standing orders.
- `list_files` / `read_file` / `write_file` — your private tree. Nobody else can see or touch it.
- `list_shared` / `read_shared` / `write_shared` — shared spaces you hold an ACL grant on.

The fleet key in the URL only opens the endpoint — it proves membership,
not identity. Never paste the plugin URL or the fleet key into chat or code.
MD;

        $readme = <<<MD
# melfina

Generated by a private Melfina install — the **fleet package**: one plugin
covers every agent. It carries the fleet key, so treat the repo URL and
these files like a password.

Before an agent connects, replace `YOUR_AGENT_NAME` in the `mcp.json` URL
with that agent's name:

    {$personaUrl}

The Basic-auth username is the persona (an agent name, not a secret); the
password is the fleet key. Percent-encode names containing `:` or `@`
(`ops:pilot` → `ops%3Apilot`). Clients that can't put credentials in the
URL can send the same values as `Authorization: Bearer <fleet key>` plus an
`X-Melfina-Agent-Key: <agent name>` header instead.

The endpoint is {$endpoint}.
MD;

        return array_merge($this->fleetManifestFiles(), [
            'mcp.json' => $mcp,
            '.mcp.json' => $mcp,
            'skills/melfina/SKILL.md' => $skill."\n",
            'README.md' => $readme."\n",
        ]);
    }

    /**
     * Repo-root-relative path → contents for the public fleet package.
     * Deliberately credential-free — this repo is served without auth at
     * an unguessable URL, so every sensitive value is a placeholder the
     * installer substitutes: `YOUR_NAME` (the persona) and `YOUR_KEY`
     * (the agent's own `melf_…` key, or the fleet key).
     *
     * @return array<string, string>
     */
    private function publicPackageFiles(): array
    {
        $endpoint = $this->endpoint();

        $personaUrl = preg_replace(
            '#^(https?://)#',
            '${1}YOUR_NAME:YOUR_KEY@',
            $endpoint
        );

        $mcp = json_encode([
            '$schema' => 'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json',
            'mcpServers' => [
                'melfina' => [
                    'type' => 'streamable-http',
                    'url' => $personaUrl,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $skill = <<<'MD'
---
name: melfina
description: Read and write your private Melfina brain — your file tree, layered instructions, and shared spaces.
---

This plugin connects you to your own Melfina brain. One copy serves every
agent on the install — before connecting, edit `mcp.json`: replace
`YOUR_NAME` with your agent name (a non-secret id you know from your own
instructions) and `YOUR_KEY` with your own `melf_…` key. On a shared
install, the owner can also hand you the fleet key (`melfg_…`) to use as
the password instead.

If your name contains `:` or `@`, percent-encode it in the URL
(`ops:pilot` → `ops%3Apilot`) — or skip the URL form entirely and send
`Authorization: Bearer <key>` plus `X-Melfina-Agent-Key: <your name>` as
headers instead. You can also just pass your name as the `name` argument
on any tool call — every tool accepts it.

- `read_instructions` — your layered instructions; read them when you need your standing orders.
- `list_files` / `read_file` / `write_file` — your private tree. Nobody else can see or touch it.
- `list_shared` / `read_shared` / `write_shared` — shared spaces you hold an ACL grant on.

Never paste your key or a filled-in mcp.json into chat or code.
MD;

        $readme = <<<MD
# melfina

Generated by a private Melfina install — the **fleet package**: one plugin
covers every agent. This repo is public but unlisted and contains no
secrets — every credential in it is a placeholder.

Before an agent connects, it edits `mcp.json`:

    {$personaUrl}

`YOUR_NAME` → the agent's name (the persona, not a secret) —
percent-encode `:` or `@` (`ops:pilot` → `ops%3Apilot`).
`YOUR_KEY`  → the agent's own `melf_…` key, or the install's fleet key
(`melfg_…`) for shared setups where the agent key isn't known.

Header-style clients can send the same as `Authorization: Bearer <key>` —
with a fleet Bearer, add `X-Melfina-Agent-Key: <agent name>`.

The endpoint is {$endpoint}.
MD;

        return array_merge($this->fleetManifestFiles(), [
            'mcp.json' => $mcp,
            '.mcp.json' => $mcp,
            'skills/melfina/SKILL.md' => $skill."\n",
            'README.md' => $readme."\n",
        ]);
    }

    /**
     * The manifest files identical across every fleet package variant
     * (Basic-gated and public) — the per-variant files (mcp.json, SKILL,
     * README) carry the credential wiring.
     *
     * @return array<string, string>
     */
    private function fleetManifestFiles(): array
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
                'skills' => ['skills/melfina/SKILL.md'],
                'apps' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            'plugin.json' => json_encode([
                '$schema' => 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json',
                'name' => 'melfina',
                'version' => '1.0.0',
                'description' => $description,
                'author' => ['name' => 'Melfina'],
                'repository' => url('/'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            '.claude-plugin/plugin.json' => json_encode([
                'name' => 'melfina',
                'version' => '1.0.0',
                'description' => $description,
                'author' => ['name' => 'Melfina'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            'LICENSE' => "Proprietary. Generated by a private Melfina install for its agents only.\n",
        ];
    }
}
