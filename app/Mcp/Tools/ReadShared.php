<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\SharedFile;
use App\Models\SharedSpace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('read_shared')]
#[Description('Read a file from a shared space you have a grant on.')]
class ReadShared extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'space' => 'required|string',
            'path' => 'required|string',
        ]);

        $space = SharedSpace::query()->where('name', $request->get('space'))->first();

        // Unknown space and missing grant are deliberately indistinguishable.
        if ($space === null || ! $space->canRead($this->agent())) {
            return Response::error('Shared space not found.');
        }

        try {
            $content = SharedFile::readFor($space, $request->get('path'));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        if ($content === null) {
            return Response::error('File not found.');
        }

        return Response::text($content);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'space' => $schema->string()->description('Shared space name.')->required(),
            'path' => $schema->string()->description('Path of the file inside the space.')->required(),
            ...$this->personaSchema($schema),
        ];
    }
}
