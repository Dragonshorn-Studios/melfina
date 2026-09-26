<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\SharedFile;
use App\Models\SharedSpace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_shared')]
#[Description('List shared spaces you can read — or, given a space name, the paths inside it.')]
class ListShared extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'space' => 'nullable|string',
        ]);

        $agent = $this->agent();
        $spaceName = $request->get('space');

        if ($spaceName === null) {
            return Response::text(json_encode(SharedSpace::readableBy($agent)->values()->all()));
        }

        $space = SharedSpace::query()->where('name', $spaceName)->first();

        if ($space === null || ! $space->canRead($agent)) {
            return Response::error('Shared space not found.');
        }

        return Response::text(json_encode(SharedFile::listFor($space)->values()->all()));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'space' => $schema->string()->description('Optional shared space name; when given, lists paths inside it.'),
            ...$this->personaSchema($schema),
        ];
    }
}
