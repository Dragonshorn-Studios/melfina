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

#[Name('read_instructions')]
#[Description('Read your instruction layers: base content plus ordered sub-layers.')]
class ReadInstructions extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $instruction = $this->agent()->instruction;

        return Response::text(json_encode([
            'content' => $instruction?->content ?? '',
            'layers' => $instruction?->layersList() ?? [],
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
