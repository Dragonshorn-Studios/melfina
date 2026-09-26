<?php

namespace App\Mcp;

use App\Mcp\Servers\MelfinaServer;
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
     * tool name => scope a scoped API key (`melfs_…`) must carry to call
     * it over HTTP. Dedicated agent keys (`melf_…`) and fleet keys
     * (`melfg_…`) are unscoped — they authenticate the agent itself.
     *
     * @var array<string, string>
     */
    private const SCOPES = [
        'list_files' => 'files:read',
        'read_file' => 'files:read',
        'write_file' => 'files:write',
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
    ];

    /**
     * @return list<array{name: string, class: class-string<Tool>, tool: Tool, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>|null, scope: string}>
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
     * @return array{name: string, class: class-string<Tool>, tool: Tool, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>|null, scope: string}|null
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
     * Every scope a scoped API key may carry — the checkbox list the
     * console renders and issue() validates against.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values(array_unique(array_values(self::SCOPES)));
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
     * @return array{name: string, class: class-string<Tool>, tool: Tool, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>|null, scope: string}|null
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
        ];
    }
}
