<?php

namespace App\Mcp;

use App\Mcp\Servers\MelfinaServer;
use App\Models\AgentApiKey;
use Laravel\Mcp\Server\Tool;

/**
 * The single source of truth behind the MCP tool surface: every adapter —
 * the MCP server itself, the HTTP fallback (POST /api/tools/{tool}), and
 * the generated SKILL.md files — is derived from the descriptors here, so
 * a tool never gains an HTTP route without its contract coming along.
 *
 * A tool joins the HTTP surface by being in {@see MelfinaServer::TOOLS}
 * and having a scope in SCOPES; an unmapped tool is unreachable over HTTP
 * (fails closed) rather than silently exposed unscoped.
 */
class ToolCatalog
{
    /**
     * Pseudo-scopes a scoped API key may carry instead of enumerating
     * individual tool scopes: `all:read` covers every `*:read` scope and
     * `all:write` covers every scope (all read + write). They are checked
     * in {@see AgentApiKey::allows()} rather than expanded at issue time,
     * so a preset key keeps covering tools added later.
     */
    public const SCOPE_ALL_READ = 'all:read';

    public const SCOPE_ALL_WRITE = 'all:write';

    /**
     * tool name => scope a scoped API key (`melfs_…`) must carry to call
     * it over HTTP. Dedicated agent keys (`melf_…`) and fleet keys
     * (`melfg_…`) are unscoped — they authenticate the agent itself.
     *
     * @var array<string, string>
     */
    private const SCOPES = [
        'list_files' => 'files:read',
        'read_file' => 'files:read',
        'read_file_binary' => 'files:read',
        'write_file' => 'files:write',
        'write_file_binary' => 'files:write',
        'read_instructions' => 'instructions:read',
        'list_shared' => 'shared:read',
        'read_shared' => 'shared:read',
        'write_shared' => 'shared:write',
        'read_persona' => 'persona:read',
        'update_persona' => 'persona:write',
        'list_agents' => 'agents:read',
        'list_message_recipients' => 'messages:read',
        'list_inbox' => 'messages:read',
        'list_sent_messages' => 'messages:read',
        'read_message' => 'messages:read',
        'send_message' => 'messages:write',
        'ack_message' => 'messages:write',
        'knowledge_lookup' => 'knowledge:read',
        'knowledge_search' => 'knowledge:read',
        'knowledge_upsert' => 'knowledge:write',
        'knowledge_connect' => 'knowledge:write',
        'knowledge_add_alias' => 'knowledge:write',
        'knowledge_link_persona' => 'knowledge:write',
    ];

    /**
     * Tools whose successful text result is a JSON payload — the HTTP
     * adapter decodes `result` for these, so API consumers get objects
     * instead of JSON-encoded strings. Text tools (read_file,
     * write_file, …) are deliberately absent: their payload is opaque
     * bytes and must round-trip as a string even when it looks like
     * JSON.
     *
     * @var list<string>
     */
    private const JSON_TOOLS = [
        'ack_message',
        'knowledge_add_alias',
        'knowledge_connect',
        'knowledge_link_persona',
        'knowledge_lookup',
        'knowledge_search',
        'knowledge_upsert',
        'list_agents',
        'list_files',
        'list_inbox',
        'read_file_binary',
        'list_message_recipients',
        'list_sent_messages',
        'list_shared',
        'read_instructions',
        'read_message',
        'read_persona',
        'send_message',
    ];

    /**
     * @return list<array{name: string, class: class-string<Tool>, tool: Tool, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>|null, scope: string, json: bool}>
     */
    public function all(): array
    {
        return collect(MelfinaServer::TOOLS)
            ->map(fn (string $class): ?array => $this->describe($class))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{name: string, class: class-string<Tool>, tool: Tool, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>|null, scope: string, json: bool}|null
     */
    public function find(string $name): ?array
    {
        foreach (MelfinaServer::TOOLS as $class) {
            $descriptor = $this->describe($class);

            if ($descriptor !== null && $descriptor['name'] === $name) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * Every concrete scope a scoped API key may carry.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values(array_unique(array_values(self::SCOPES)));
    }

    /**
     * Convenience scopes that stand in for whole classes of concrete
     * scopes (`all:read`, `all:write`).
     *
     * @return list<string>
     */
    public function presets(): array
    {
        return [self::SCOPE_ALL_READ, self::SCOPE_ALL_WRITE];
    }

    /**
     * Every scope value a scoped API key may carry — presets first — the
     * checkbox list the console renders and issue() validates against.
     *
     * @return list<string>
     */
    public function scopeChoices(): array
    {
        return array_merge($this->presets(), $this->scopes());
    }

    /**
     * The scope a named tool requires of a scoped API key, or null when
     * the tool isn't on the HTTP surface (unknown or unscoped — the
     * caller fails closed either way).
     */
    public function scopeFor(string $name): ?string
    {
        return self::SCOPES[$name] ?? null;
    }

    /**
     * @param  class-string<Tool>  $class
     * @return array{name: string, class: class-string<Tool>, tool: Tool, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>|null, scope: string, json: bool}|null
     */
    private function describe(string $class): ?array
    {
        /** @var Tool $tool */
        $tool = app($class);
        $name = $tool->name();
        $scope = $this->scopeFor($name);

        if ($scope === null) {
            return null;
        }

        $shape = $tool->toArray();

        return [
            'name' => $name,
            'class' => $class,
            'tool' => $tool,
            'description' => $tool->description(),
            'inputSchema' => $shape['inputSchema'] ?? [],
            'outputSchema' => $shape['outputSchema'] ?? null,
            'scope' => $scope,
            'json' => in_array($name, self::JSON_TOOLS, true),
        ];
    }
}
