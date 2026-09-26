<?php

namespace App\Mcp;

use App\Models\Agent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Builds and serves the "fake plugin repository" for an agent: a real,
 * dumb-HTTP-cloneable git repo whose package wires the install's MCP
 * endpoint to the credentials carried in the clone URL.
 *
 * The clone URL embeds the credentials, so the repo only ever materializes
 * for someone already holding them:
 *
 *     /plugins/{agentKey}.git                    → Bearer melf_… (direct persona)
 *     /plugins/{fleetKey}/{agentKey|name}.git    → Bearer melfg_… + X-Melfina-Agent-Key: <name>
 *
 * The fleet form's persona header carries the agent's *name*, not its key —
 * the name is a non-secret id the agent knows from its own instructions.
 *
 * Built repos are cached under storage/app/mcp-plugins and rebuilt whenever
 * the embedded credentials or endpoint change (fingerprint check).
 */
class PluginRepo
{
    /**
     * Ensure a fresh repo exists for these credentials and return its path.
     * Both key arguments are plaintexts taken from the request URL.
     */
    public function ensureBuilt(Agent $agent, string $agentKey, ?string $fleetKey): string
    {
        $dir = $this->repoDir($agent);
        $fingerprint = $this->fingerprint($agentKey, $fleetKey);

        if (
            is_file($dir.'/info/refs')
            && is_file($dir.'.fingerprint')
            && trim((string) file_get_contents($dir.'.fingerprint')) === $fingerprint
        ) {
            return $dir;
        }

        $this->build($agent, $agentKey, $fleetKey, $fingerprint);

        return $dir;
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

        if ($full === false || ! str_starts_with($full, $base) || ! is_file($full)) {
            return null;
        }

        return $full;
    }

    /**
     * Regenerate the flat package directory used for ZIP downloads. Called
     * when a plaintext agent key is in hand (create / rotate).
     */
    public function rebuildPackage(Agent $agent, string $agentKey): void
    {
        $dir = storage_path('app/mcp-plugins/'.$agent->id.'.pkg');
        File::deleteDirectory($dir);

        foreach ($this->packageFiles($agent, $agentKey, null) as $path => $contents) {
            $full = $dir.'/'.$path;
            File::ensureDirectoryExists(dirname($full));
            file_put_contents($full, $contents);
        }
    }

    /**
     * Drop the cached repo for an agent — its packs embed the old plaintext
     * key, so stale copies are deleted on rotation rather than left on disk.
     */
    public function dropRepo(Agent $agent): void
    {
        File::deleteDirectory($this->repoDir($agent));
        File::delete($this->repoDir($agent).'.fingerprint');
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

    public function packageDir(Agent $agent): string
    {
        return storage_path('app/mcp-plugins/'.$agent->id.'.pkg');
    }

    public function repoDir(Agent $agent): string
    {
        return storage_path('app/mcp-plugins/'.$agent->id.'.git');
    }

    private function fingerprint(string $agentKey, ?string $fleetKey): string
    {
        return hash('sha256', implode('|', [$agentKey, $fleetKey ?? '', url('/mcp'), 'v1']));
    }

    private function build(Agent $agent, string $agentKey, ?string $fleetKey, string $fingerprint): void
    {
        $work = storage_path('app/mcp-plugins/build-'.Str::random(12));
        File::ensureDirectoryExists($work);

        foreach ($this->packageFiles($agent, $agentKey, $fleetKey) as $path => $contents) {
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

        $dir = $this->repoDir($agent);
        File::ensureDirectoryExists(dirname($dir));
        File::deleteDirectory($dir);
        File::moveDirectory($work.'/.git', $dir);
        file_put_contents($dir.'.fingerprint', $fingerprint);
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
    private function packageFiles(Agent $agent, string $agentKey, ?string $fleetKey): array
    {
        $endpoint = url('/mcp');
        $slug = Str::slug($agent->name) ?: 'agent';
        $name = 'melfina-'.$slug;

        $headers = $fleetKey !== null
            ? [
                'Authorization' => 'Bearer '.$fleetKey,
                'X-Melfina-Agent-Key' => $agent->name,
            ]
            : ['Authorization' => 'Bearer '.$agentKey];

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
}
