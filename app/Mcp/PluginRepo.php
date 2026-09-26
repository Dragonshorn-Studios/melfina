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
 * Built repos are cached under storage/app/mcp-plugins/{id}.{mode}.git (and
 * fleet.git for the global package) and rebuilt whenever the embedded
 * credential, persona name, or endpoint change (fingerprint check under a
 * cache lock).
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
        $fingerprint = $this->fingerprint($agent, $mode, $credential);

        if ($this->fresh($dir, $fingerprint)) {
            return $dir;
        }

        // Serialize builds per agent+mode: concurrent clones wait for the
        // first build instead of racing the directory swap.
        return Cache::lock('melfina-plugin-repo:'.$agent->id.':'.$mode, 30)
            ->block(10, function () use ($agent, $mode, $credential, $dir, $fingerprint) {
                if ($this->fresh($dir, $fingerprint)) {
                    return $dir;
                }

                $this->build($dir, $this->packageFiles($agent, $mode, $credential), $fingerprint);

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
        $fingerprint = hash('sha256', implode('|', ['global', $fleetKey, $this->endpoint(), 'v1']));

        if ($this->fresh($dir, $fingerprint)) {
            return $dir;
        }

        return Cache::lock('melfina-plugin-repo:global', 30)
            ->block(10, function () use ($dir, $fingerprint, $fleetKey) {
                if ($this->fresh($dir, $fingerprint)) {
                    return $dir;
                }

                $this->build($dir, $this->globalPackageFiles($fleetKey), $fingerprint);

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
        $fingerprint = hash('sha256', implode('|', ['public', $this->endpoint(), 'v1']));

        if ($this->fresh($dir, $fingerprint)) {
            return $dir;
        }

        return Cache::lock('melfina-plugin-repo:global-public', 30)
            ->block(10, function () use ($dir, $fingerprint) {
                if ($this->fresh($dir, $fingerprint)) {
                    return $dir;
                }

                $this->build($dir, $this->publicPackageFiles(), $fingerprint);

                return $dir;
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
        // also match sibling files like `{id}.git.fingerprint`.
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

    private function fresh(string $dir, string $fingerprint): bool
    {
        return is_file($dir.'/info/refs')
            && is_file($dir.'.fingerprint')
            && trim((string) file_get_contents($dir.'.fingerprint')) === $fingerprint;
    }

    private function fingerprint(Agent $agent, string $mode, string $credential): string
    {
        return hash('sha256', implode('|', [$agent->name, $mode, $credential, $this->endpoint(), 'v1']));
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
    private function build(string $dir, array $files, string $fingerprint): void
    {
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

        foreach ($steps as $args) {
            $process = new Process(array_merge(['git'], $args));
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
        file_put_contents($dir.'.fingerprint', $fingerprint);
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

        $description = 'Melfina brain — private files, instructions, and shared spaces for every agent on this install.';

        $minimax = json_encode([
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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $agentPlugins = json_encode([
            '$schema' => 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json',
            'name' => 'melfina',
            'version' => '1.0.0',
            'description' => $description,
            'author' => ['name' => 'Melfina'],
            'repository' => url('/'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $claude = json_encode([
            'name' => 'melfina',
            'version' => '1.0.0',
            'description' => $description,
            'author' => ['name' => 'Melfina'],
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

        $license = "Proprietary. Generated by a private Melfina install for its agents only.\n";

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

        $description = 'Melfina brain — private files, instructions, and shared spaces for every agent on this install.';

        $minimax = json_encode([
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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $agentPlugins = json_encode([
            '$schema' => 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json',
            'name' => 'melfina',
            'version' => '1.0.0',
            'description' => $description,
            'author' => ['name' => 'Melfina'],
            'repository' => url('/'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $claude = json_encode([
            'name' => 'melfina',
            'version' => '1.0.0',
            'description' => $description,
            'author' => ['name' => 'Melfina'],
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
headers instead.

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

        $license = "Proprietary. Generated by a private Melfina install for its agents only.\n";

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
}
