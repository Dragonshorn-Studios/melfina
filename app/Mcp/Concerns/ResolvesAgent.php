<?php

namespace App\Mcp\Concerns;

use App\Http\Middleware\ResolveAgent;
use App\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The agent placed on the request by {@see ResolveAgent}.
 */
trait ResolvesAgent
{
    /**
     * The persona argument shared by every tool — how an imp names itself
     * when the connection carries a shared fleet key. Declared in the
     * schema so agents discover it from the tool listing.
     *
     * @return array<string, Type>
     */
    protected function personaSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Your agent name — your persona on this install. Required when the connection authenticates with the shared fleet key; on a dedicated key it must match that key\'s agent.'),
        ];
    }

    protected function agent(): Agent
    {
        $agent = request()->attributes->get('agent');

        assert($agent instanceof Agent);

        return $agent;
    }
}
