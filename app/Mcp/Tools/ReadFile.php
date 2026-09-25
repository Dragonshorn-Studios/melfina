<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\VfsFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('read_file')]
#[Description('Read a file from your private file tree.')]
class ReadFile extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        try {
            $content = VfsFile::readFor($this->agent(), $request->get('path'));
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
            'path' => $schema->string()->description('Path of the file to read, e.g. "notes/todo.txt".')->required(),
        ];
    }
}
