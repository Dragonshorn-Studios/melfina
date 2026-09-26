<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\User;
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
        $agent = $this->agent();
        $instruction = $agent->instruction;

        // The owner's shared operator persona rides on every read — agents
        // always know who the human behind the console is.
        $operator = User::operatorPersona();

        return Response::text(json_encode([
            'content' => $instruction?->content ?? '',
            'layers' => $instruction?->layersList() ?? [],
            'operator' => $operator ?? '',
            'persona_hint' => 'Your persona and memories live in your brain — read them with read_persona, maintain them with update_persona.',
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
