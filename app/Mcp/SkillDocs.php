<?php

namespace App\Mcp;

use Illuminate\Support\Str;

/**
 * The skill layer: generates the skill artifacts that teach an agent
 * runtime without MCP support how to call the same tools over plain JSON
 * HTTP (`POST /api/tools/{tool}`). Two layouts — see PackageVariant:
 *
 * - multi:  one thin skills/melfina-<tool>/SKILL.md per catalogued tool.
 * - single: just the umbrella skill plus scripts/melfina-call.mjs, a
 *   zero-dependency Node shim that discovers the surface at run time and
 *   carries the error handling (arg checks, 429/5xx retries, status-code
 *   hints) that per-tool prose can't.
 *
 * Every file derives from ToolCatalog — the same descriptors the MCP
 * server and the HTTP adapter share — so adding a tool to the catalog
 * mechanically produces its fallback surface. No credentials are ever
 * baked into generated output: skills and the script reference the
 * `$MELFINA_API_KEY` environment variable the target runtime injects.
 */
class SkillDocs
{
    public function __construct(private ToolCatalog $catalog) {}

    /**
     * Repo-relative path → contents for the generated package additions:
     * melfina-api.json (the machine-readable surface manifest) plus the
     * variant's skill layer — per-tool SKILL.md files for `multi`, or
     * scripts/melfina-call.mjs for `single`.
     *
     * @return array<string, string>
     */
    public function files(string $baseUrl, PackageVariant $variant = PackageVariant::Multi): array
    {
        $files = ['melfina-api.json' => $this->manifest($baseUrl)];

        if ($variant === PackageVariant::Single) {
            $files['scripts/melfina-call.mjs'] = self::callScript();
        } else {
            foreach ($this->catalog->all() as $tool) {
                $files['skills/'.self::skillName($tool['name']).'/SKILL.md'] = $this->skill($tool, $baseUrl);
            }
        }

        return $files;
    }

    /**
     * Paths of every generated SKILL.md beyond the umbrella — the `skills`
     * manifest array in .minimax-plugin/plugin.json must list them all
     * for the plugin to register on skill-only runtimes. The `single`
     * variant registers only the umbrella.
     *
     * @return list<string>
     */
    public function skillPaths(PackageVariant $variant = PackageVariant::Multi): array
    {
        if ($variant === PackageVariant::Single) {
            return [];
        }

        $paths = [];

        foreach ($this->catalog->all() as $tool) {
            $paths[] = 'skills/'.self::skillName($tool['name']).'/SKILL.md';
        }

        return $paths;
    }

    /**
     * The MCP-first paragraph appended to a package's umbrella
     * skills/melfina/SKILL.md — the top-level instruction pointing
     * skill-only agents at the package's HTTP fallback while keeping MCP
     * the canonical path.
     */
    public function appendix(string $baseUrl, PackageVariant $variant = PackageVariant::Multi, bool $credentialEmbedded = false): string
    {
        $credentialNote = $credentialEmbedded
            ? "\nThis package's mcp.json already carries a Bearer credential — export that same key as \$MELFINA_API_KEY for the HTTP calls.\n"
            : '';

        if ($variant === PackageVariant::Single) {
            $base = rtrim($baseUrl, '/');

            return <<<MD

## HTTP fallback + bundled call script

Melfina provides internal tools and memory. Before using the HTTP fallback, check whether the Melfina MCP server is available. Prefer MCP whenever possible — and never call both paths for the same operation.

If your runtime cannot run an MCP server, this package ships a zero-dependency Node shim that drives the whole tool surface over plain JSON HTTP:

    node scripts/melfina-call.mjs list
    node scripts/melfina-call.mjs schema <tool>
    node scripts/melfina-call.mjs call <tool> '<args-json>'

The script reads `melfina-api.json` for the endpoint and input schemas, checks required arguments before sending, retries `429`/`5xx` responses with backoff, and on failure prints the server's `error` plus a concrete hint (missing scope, missing fleet persona, bad arguments). It needs Node 18+ and a key in `\$MELFINA_API_KEY`; on a fleet key it also sends your agent name via `--name` or `\$MELFINA_AGENT_NAME`. Credentials are never stored in these files.{$credentialNote}
No Node available? The raw contract is `POST {$base}/api/tools/<tool>` with `Authorization: Bearer \$MELFINA_API_KEY` — `GET {$base}/api/tools` lists the surface, and `melfina-api.json` documents every status code.
MD;
        }

        return <<<MD

## HTTP fallback skills

Melfina provides internal tools and memory. Before using a Melfina HTTP skill, check whether the Melfina MCP server is available. Prefer MCP whenever possible.

If your runtime cannot run an MCP server, every catalogued tool is also
callable over plain JSON HTTP — see the generated skills under
`skills/melfina-*/` (one per catalogued tool) and the machine-readable
`melfina-api.json`. Each skill repeats the precedence rule and forbids
calling both paths for the same operation.

HTTP calls authenticate with a Bearer key injected by your runtime —
`Authorization: Bearer \$MELFINA_API_KEY`. Credentials are never stored
in these files.{$credentialNote}
MD;
    }

    /**
     * Machine-readable manifest of the whole HTTP tool surface: the
     * endpoint pattern, the accepted credential forms, and every tool's
     * name, scope, skill path, and input schema. `base` is the plain
     * origin the bundled call script derives its URLs from.
     */
    private function manifest(string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');

        return json_encode([
            'name' => 'melfina',
            'description' => 'Melfina tool surface over HTTP — the MCP-first fallback for runtimes without MCP support.',
            'precedence' => 'Prefer the Melfina MCP server when available. Do not call both MCP and HTTP for the same operation.',
            'mcp' => $base.'/mcp',
            'base' => $base,
            'endpoint' => $base.'/api/tools/{tool}',
            'index' => $base.'/api/tools',
            'script' => 'scripts/melfina-call.mjs — `node scripts/melfina-call.mjs call <tool> \'<args-json>\'` (single-skill packages only)',
            'auth' => [
                'bearer' => 'Authorization: Bearer $MELFINA_API_KEY — melf_… (dedicated), melfs_… (scoped), or melfg_… (fleet)',
                'basic' => 'https://<agent name>:$MELFINA_API_KEY@host/api/tools/{tool} — Basic username pins the persona on fleet keys',
                'persona' => 'fleet keys name the agent per call via the `name` argument, an X-Melfina-Agent-Key header, or the ?agent_key=<name> query param (names only)',
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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The zero-dependency Node shim shipped in `single` packages —
     * `node scripts/melfina-call.mjs` turns the manifest into a CLI for
     * the whole tool surface, with argument checks, retries, and
     * status-code hints baked in. Static source: it reads the endpoint
     * from the packaged melfina-api.json at run time, so the file is
     * identical across installs.
     */
    private static function callScript(): string
    {
        return <<<'JS'
#!/usr/bin/env node
/*
 * melfina-call.mjs — zero-dependency CLI shim over the Melfina HTTP tool
 * surface. Generated into this plugin package; reads melfina-api.json
 * from the package root for the endpoint and per-tool input schemas.
 * Requires Node.js 18+ (global fetch). Credentials come from the
 * environment — never edit a key into this file:
 *
 *   MELFINA_API_KEY    a melf_…, melfs_…, or melfg_… key (required)
 *   MELFINA_AGENT_NAME agent persona on a fleet (melfg_…) key
 *   MELFINA_BASE_URL   optional override for the packaged endpoint
 *
 * Usage:
 *   node scripts/melfina-call.mjs list                   tool surface
 *   node scripts/melfina-call.mjs schema <tool>          input schema
 *   node scripts/melfina-call.mjs call <tool> '<json>'   invoke a tool
 *   node scripts/melfina-call.mjs <tool> '<json>'        call shorthand
 *   --name <agent>   persona for this call (fleet keys)
 *   --raw            print the untouched response envelope
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const pkg = join(dirname(fileURLToPath(import.meta.url)), '..');

const usage = `usage:
  node scripts/melfina-call.mjs list                   tool surface
  node scripts/melfina-call.mjs schema <tool>          input schema
  node scripts/melfina-call.mjs call <tool> '<json>'   invoke a tool
  node scripts/melfina-call.mjs <tool> '<json>'        call shorthand
  --name <agent>   persona for this call (fleet keys)
  --raw            print the untouched response envelope`;

const fail = (message, hint) => {
  process.stderr.write(`melfina-call: ${message}\n`);
  if (hint) process.stderr.write(`  hint: ${hint}\n`);
  process.exit(1);
};

let manifest;
try {
  manifest = JSON.parse(readFileSync(join(pkg, 'melfina-api.json'), 'utf8'));
} catch {
  fail('melfina-api.json is missing or unreadable', 'run this script inside the plugin package it shipped with');
}

const base = (process.env.MELFINA_BASE_URL || manifest.base || '').replace(/\/+$/, '');
if (!base) fail('no endpoint', 'melfina-api.json carries no base — or set $MELFINA_BASE_URL');

const argv = process.argv.slice(2);
let persona = process.env.MELFINA_AGENT_NAME || '';
let raw = false;
const positional = [];
for (let i = 0; i < argv.length; i++) {
  if (argv[i] === '--name') {
    persona = argv[++i] || '';
  } else if (argv[i] === '--raw') {
    raw = true;
  } else {
    positional.push(argv[i]);
  }
}

const [command, ...rest] = positional;

if (!command || command === 'help' || command === '--help' || command === '-h') {
  console.log(usage);
  process.exit(0);
}

// `schema` is purely local — only networked commands need the key.
const key = process.env.MELFINA_API_KEY;
const needKey = () => {
  if (!key) fail('$MELFINA_API_KEY is not set', 'export a melf_… / melfs_… / melfg_… key — never edit one into files');
};

const headers = { Authorization: `Bearer ${key}` };
if (persona) headers['X-Melfina-Agent-Key'] = persona;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Retry budget for transient failures: throttling (429), server errors
// (5xx), and network failures. Honor Retry-After; otherwise back off
// exponentially. Caller errors (4xx) come straight back.
async function request(method, url, body) {
  let lastErr;
  for (let attempt = 0; attempt < 4; attempt++) {
    if (attempt) await sleep(Math.min(500 * 2 ** attempt, 10000));
    let res;
    try {
      res = await fetch(url, {
        method,
        headers: body === undefined ? headers : { ...headers, 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
    } catch (err) {
      lastErr = err;
      continue;
    }
    if (res.status === 429 || res.status >= 500) {
      const retryAfter = Number(res.headers.get('retry-after'));
      if (Number.isFinite(retryAfter) && retryAfter > 0) {
        await sleep(Math.min(retryAfter * 1000, 30000));
      }
      lastErr = new Error(`HTTP ${res.status}`);
      continue;
    }
    return res;
  }
  throw lastErr;
}

const toolEntry = (name) => (manifest.tools || []).find((t) => t.name === name);

function typeOk(value, type) {
  const types = Array.isArray(type) ? type : [type];
  return types.some((t) => {
    switch (t) {
      case 'string': return typeof value === 'string';
      case 'number': return typeof value === 'number';
      case 'integer': return Number.isInteger(value);
      case 'boolean': return typeof value === 'boolean';
      case 'array': return Array.isArray(value);
      case 'object': return value !== null && typeof value === 'object' && !Array.isArray(value);
      default: return true;
    }
  });
}

// Pre-flight the required/type fields declared in melfina-api.json so a
// malformed call fails locally with a readable message instead of a 422.
// Unknown arguments pass through — server-side validation owns the rest.
function checkArgs(tool, callArgs) {
  const entry = toolEntry(tool);
  if (!entry) return;
  const schema = entry.inputSchema || {};
  const problems = (schema.required || [])
    .filter((k) => !(k in callArgs))
    .map((k) => `missing required "${k}"`);
  for (const [k, prop] of Object.entries(schema.properties || {})) {
    if (k in callArgs && prop.type && !typeOk(callArgs[k], prop.type)) {
      problems.push(`"${k}" must be ${Array.isArray(prop.type) ? prop.type.join(' | ') : prop.type}`);
    }
  }
  if (problems.length) {
    fail(`invalid arguments for ${tool}`, `${problems.join('\n  ')}\n  schema: node scripts/melfina-call.mjs schema ${tool}`);
  }
}

const hintFor = (status, tool) => ({
  400: 'tool-level failure — the MCP equivalent of an isError result',
  401: `authentication failed — check $MELFINA_API_KEY${key.startsWith('melfg_') ? ' and that --name / $MELFINA_AGENT_NAME names a live agent' : ''}`,
  403: `this key lacks the required scope — see the "scope" field for ${tool} in melfina-api.json`,
  404: 'unknown tool — run `node scripts/melfina-call.mjs list`',
  422: `invalid arguments — run \`node scripts/melfina-call.mjs schema ${tool}\``,
}[status]);

function parseArgs(text) {
  let parsed;
  try {
    parsed = JSON.parse(text);
  } catch {
    fail('arguments must be a JSON object', `example: call read_file '{"path": "notes.md"}'`);
  }
  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    fail('arguments must be a JSON object', `example: call read_file '{"path": "notes.md"}'`);
  }
  return parsed;
}

async function listTools() {
  const res = await request('GET', `${base}/api/tools`);
  const data = await res.json().catch(() => null);
  if (!data || !data.ok) {
    fail((data && data.error) || `HTTP ${res.status}`, hintFor(res.status, ''));
  }
  for (const t of data.tools || []) {
    console.log(`${t.name}  [${t.scope}]\n    ${t.description}`);
  }
}

async function call(tool, text) {
  if (!toolEntry(tool)) {
    fail(`unknown tool "${tool}"`, 'run `node scripts/melfina-call.mjs list` — or re-export the package if it was added to Melfina recently');
  }
  const callArgs = parseArgs(text ?? '{}');
  checkArgs(tool, callArgs);
  const res = await request('POST', `${base}/api/tools/${tool}`, callArgs);
  const data = await res.json().catch(() => null);
  if (!data) fail(`HTTP ${res.status}`, 'response was not JSON — is the endpoint reachable and correct?');
  if (!data.ok) {
    const fields = data.fields ? ` fields: ${JSON.stringify(data.fields)}` : '';
    fail(data.error || `HTTP ${res.status}`, (hintFor(res.status, tool) || 'unexpected error') + fields);
  }
  if (raw) return void console.log(JSON.stringify(data, null, 2));
  if (typeof data.result === 'string') return void console.log(data.result);
  console.log(JSON.stringify(data.result, null, 2));
}

try {
  switch (command) {
    case 'list':
      needKey();
      await listTools();
      break;
    case 'schema': {
      const entry = rest[0] && toolEntry(rest[0]);
      if (!entry) fail(`unknown tool "${rest[0] ?? ''}"`, 'run `node scripts/melfina-call.mjs list`');
      console.log(JSON.stringify(entry.inputSchema, null, 2));
      break;
    }
    case 'call': {
      needKey();
      const [tool, ...json] = rest;
      if (!tool) fail('usage: call <tool> <json>');
      await call(tool, json.join(' '));
      break;
    }
    default:
      needKey();
      await call(command, rest.join(' '));
  }
} catch (err) {
  fail(`request to ${base} failed after retries: ${err.message}`, 'is MELFINA_BASE_URL / the packaged endpoint reachable?');
}
JS;
    }

    /**
     * @param  array{name: string, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>|null, scope: string, json: bool}  $tool
     */
    private function skill(array $tool, string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');
        $host = Str::after($base, '://');
        $schema = json_encode($tool['inputSchema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $scope = $tool['scope'];

        $name = self::skillName($tool['name']);

        // Descriptions contain `: ` (e.g. "Internal nouns first: …") —
        // a JSON-encoded string is a valid YAML double-quoted scalar, so
        // the frontmatter parses on strict loaders either way.
        $description = json_encode(
            $tool['description'].' (HTTP fallback for the `'.$tool['name'].'` Melfina MCP tool.)',
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $outSchema = $tool['outputSchema'];
        $resultSchema = $outSchema === null
            ? ''
            : "\n`result` matches the tool's declared output schema:\n\n```json\n"
                .json_encode($outSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n```\n";

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
  name>"` in the body, an `X-Melfina-Agent-Key: <your agent name>`
  header, or `?agent_key=<your agent name>` in the query (names only).
  URL-only clients can pin it as the Basic username:
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
- `422` — invalid arguments; on schema violations `fields` names the
  offenders.
- `429` — too many requests (the endpoint's throttle — the response is
  the framework's, not the `ok`/`error` envelope).
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
