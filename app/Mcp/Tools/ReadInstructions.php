<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\Agent;
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
        return Response::text(json_encode(self::payload($this->agent())));
    }

    /**
     * The exact payload read_instructions returns for the agent — shared
     * with the console preview so the owner sees the same thing the imp
     * does.
     *
     * @return array{content: string, layers: array<int, string>, operator: string, persona_hint: string}
     */
    public static function payload(Agent $agent): array
    {
        $instruction = $agent->instruction;

        // The owner's shared operator persona rides on every read — agents
        // always know who the human behind the console is.
        $operator = User::operatorPersona();

        return [
            'content' => $instruction?->content ?? '',
            'layers' => $instruction?->layersList() ?? [],
            'operator' => $operator ?? '',
            'persona_hint' => 'Your persona and memories live in your brain — read them with read_persona, maintain them with update_persona.',
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
