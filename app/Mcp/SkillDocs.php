<?php

namespace App\Mcp;

use Illuminate\Support\Str;

/**
 * The skill layer: generates the thin per-tool SKILL.md files that teach
 * an agent runtime without MCP support how to call the same tools over
 * plain JSON HTTP (`POST /api/tools/{tool}`).
 *
 * Every file derives from ToolCatalog — the same descriptors the MCP
 * server and the HTTP adapter share — so adding a tool to the catalog
 * mechanically produces its fallback skill. No credentials are ever baked
 * into generated output: skills reference the `$MELFINA_API_KEY`
 * environment variable the target runtime injects.
 */
class SkillDocs
{
    public function __construct(private ToolCatalog $catalog) {}

    /**
     * Repo-relative path → contents for the generated package additions:
     * one skills/melfina-<tool>/SKILL.md per catalogued tool plus
     * melfina-api.json, the machine-readable surface manifest.
     *
     * @return array<string, string>
     */
    public function files(string $baseUrl): array
    {
        $files = ['melfina-api.json' => $this->manifest($baseUrl)];

        foreach ($this->catalog->all() as $tool) {
            $files['skills/'.self::skillName($tool['name']).'/SKILL.md'] = $this->skill($tool, $baseUrl);
        }

        return $files;
    }

    /**
     * Paths of every generated SKILL.md — the `skills` manifest array in
     * .minimax-plugin/plugin.json must list them all for the plugin to
     * register on skill-only runtimes.
     *
     * @return list<string>
     */
    public function skillPaths(): array
    {
        $paths = [];

        foreach ($this->catalog->all() as $tool) {
            $paths[] = 'skills/'.self::skillName($tool['name']).'/SKILL.md';
        }

        return $paths;
    }

    /**
     * The MCP-first paragraph appended to a package's umbrella
     * skills/melfina/SKILL.md — the top-level instruction pointing
     * skill-only agents at the generated HTTP skills while keeping MCP
     * the canonical path.
     */
    public function appendix(bool $credentialEmbedded = false): string
    {
        $credentialNote = $credentialEmbedded
            ? "\nThis package's mcp.json already carries a Bearer credential — export that same key as \$MELFINA_API_KEY for the HTTP calls.\n"
            : '';

        return <<<MD

## HTTP fallback skills

Melfina provides internal tools and memory. Before using a Melfina HTTP skill, check whether the Melfina MCP server is available. Prefer MCP whenever possible.

If your runtime cannot run an MCP server, every tool above is also
callable over plain JSON HTTP — see the generated skills under
`skills/melfina-*/` and the machine-readable `melfina-api.json`. Each
skill repeats the precedence rule and forbids calling both paths for the
same operation.

HTTP calls authenticate with a Bearer key injected by your runtime —
`Authorization: Bearer \$MELFINA_API_KEY`. Credentials are never stored
in these files.{$credentialNote}
MD;
    }

    /**
     * Machine-readable manifest of the whole HTTP tool surface: the
     * endpoint pattern, the accepted credential forms, and every tool's
     * name, scope, skill path, and input schema.
     */
    private function manifest(string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');

        return json_encode([
            'name' => 'melfina',
            'description' => 'Melfina tool surface over HTTP — the MCP-first fallback for runtimes without MCP support.',
            'precedence' => 'Prefer the Melfina MCP server when available. Do not call both MCP and HTTP for the same operation.',
            'mcp' => $base.'/mcp',
            'endpoint' => $base.'/api/tools/{tool}',
            'index' => $base.'/api/tools',
            'auth' => [
                'bearer' => 'Authorization: Bearer $MELFINA_API_KEY — melf_… (dedicated), melfs_… (scoped), or melfg_… (fleet)',
                'basic' => 'https://<agent name>:$MELFINA_API_KEY@host/api/tools/{tool} — Basic username pins the persona on fleet keys',
                'persona' => 'fleet keys name the agent per call via the `name` argument or an X-Melfina-Agent-Key header',
                'scopes' => 'melfs_… keys carry a scope allowlist per tool; the `all:read` preset covers every `*:read` scope and `all:write` covers every scope',
                'credentials' => 'injected by the target runtime ($MELFINA_API_KEY); never stored in generated files',
            ],
            'tools' => collect($this->catalog->all())->map(fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'endpoint' => $base.'/api/tools/'.$tool['name'],
                'scope' => $tool['scope'],
                'inputSchema' => $tool['inputSchema'],
                'outputSchema' => $tool['outputSchema'],
                'resultType' => $tool['json'] ? 'json' : 'text',
                'skill' => 'skills/'.self::skillName($tool['name']).'/SKILL.md',
            ])->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * @param  array{name: string, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>|null, scope: string, json: bool}  $tool
     */
    private function skill(array $tool, string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');
        $host = Str::after($base, '://');
        $schema = json_encode($tool['inputSchema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $scope = $tool['scope'];

        $name = self::skillName($tool['name']);

        // Descriptions contain `: ` (e.g. "Internal nouns first: …") —
        // a JSON-encoded string is a valid YAML double-quoted scalar, so
        // the frontmatter parses on strict loaders either way.
        $description = json_encode(
            $tool['description'].' (HTTP fallback for the `'.$tool['name'].'` Melfina MCP tool.)',
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $outSchema = $tool['outputSchema'];
        $resultSchema = $outSchema === null
            ? ''
            : "\n`result` matches the tool's declared output schema:\n\n```json\n"
                .json_encode($outSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n```\n";

        $resultDoc = $tool['json']
            ? '`result` is this tool\'s JSON payload, decoded to objects/arrays (`resultType` is `json`).'
            : '`result` is this tool\'s text payload verbatim (`resultType` is `text`) — file contents stay a string even when they look like JSON.';

        return <<<MD
---
name: {$name}
description: {$description}
---

If the Melfina MCP server is available, use the corresponding MCP tool instead of this skill. Use this skill only as an HTTP fallback when MCP is unavailable.

Do not call both MCP and HTTP for the same operation.

## When to use

{$tool['description']} Use this when your runtime cannot run the Melfina
MCP server (no MCP client, or a lightweight/cloud agent) and you need the
same operation over plain JSON HTTP.

## Endpoint

    POST {$base}/api/tools/{$tool['name']}
    Content-Type: application/json

`GET {$base}/api/tools` lists the whole tool surface with scopes.
`melfina-api.json` in this package is the machine-readable manifest.

## Auth

Send a Melfina key your runtime injects — credentials never live in this
file:

    Authorization: Bearer \$MELFINA_API_KEY

Key forms:

- `melf_…` dedicated agent key — your persona is included in the key.
- `melfs_…` scoped API key — this call needs the `{$scope}` scope (403
  without it; the `all:read` preset covers every read scope and
  `all:write` every scope).
- `melfg_…` fleet key — also name your agent: `"name": "<your agent
  name>"` in the body, or an `X-Melfina-Agent-Key: <your agent name>`
  header. URL-only clients can pin it as the Basic username:
  `https://<your name>:\$MELFINA_API_KEY@{$host}/api/tools/{$tool['name']}`.

## Request

The body is the tool's arguments object — the same shape MCP `tools/call`
takes in `params.arguments`:

```json
{$schema}
```

## Response

`200 OK` returns `{"ok": true, "result": …, "resultType": "…"}`.
{$resultDoc} Multi-part results surface as `resultType: "content"`
with `result.content` holding the MCP content parts.

Errors return `{"ok": false, "error": "…"}` with a non-2xx status:

- `401` — no key, an unknown or revoked key, a disabled agent, a fleet
  key with no persona, or a `name` claim that does not match the key's
  agent.
- `403` — a scoped key (`melfs_…`) lacking the `{$scope}` scope.
- `404` — no such tool.
- `422` — invalid arguments; `fields` names the offenders.
- `400` — a tool-level error — the failure the MCP tool would return as
  `isError` (for example a not-found file or a rate limit), as the
  `error` string.
MD.$resultSchema."\n";
    }

    private static function skillName(string $tool): string
    {
        return 'melfina-'.Str::replace('_', '-', $tool);
    }
}
