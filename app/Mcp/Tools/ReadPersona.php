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

#[Name('read_persona')]
#[Description('Read your persona and memories — your self-authored identity and the memory log you keep across conversations.')]
class ReadPersona extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        return Response::text(json_encode(self::payload($this->agent())));
    }

    /**
     * The exact payload read_persona returns for the agent — shared with
     * the console preview so the owner sees the same thing the imp does.
     *
     * @return array{name: string, description: string, persona: string, memories: array<int, array{at: string, text: string}>}
     */
    public static function payload(Agent $agent): array
    {
        return [
            'name' => $agent->name,
            'description' => $agent->description ?? '',
            'persona' => $agent->persona ?? '',
            'memories' => $agent->memories ?? [],
        ];
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
