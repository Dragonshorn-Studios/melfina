<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
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
        $agent = $this->agent();

        return Response::text(json_encode([
            'name' => $agent->name,
            'description' => $agent->description ?? '',
            'persona' => $agent->persona ?? '',
            'memories' => $agent->memories ?? [],
        ]));
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
