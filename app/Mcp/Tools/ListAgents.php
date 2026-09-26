<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_agents')]
#[Description('List the other agents on this install — name plus their short public description, so you know who shares your spaces.')]
class ListAgents extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $me = $this->agent();

        $agents = Agent::query()
            ->where('status', Agent::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (Agent $agent): array => [
                'name' => $agent->name,
                'description' => $agent->description ?? '',
                'you' => $agent->id === $me->id,
            ])
            ->values();

        return Response::text(json_encode(['agents' => $agents]));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->personaSchema($schema);
    }
}
