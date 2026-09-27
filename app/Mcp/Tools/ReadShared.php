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
#[Description('Read a file from a shared space you have a grant on. Text comes back verbatim; images come back as MCP image content; other binaries need `format=base64` or a raw GET /api/shared/{space}/files/{path}.')]
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
            'format' => 'nullable|string|in:base64',
        ]);

        $space = SharedSpace::query()->where('name', $request->get('space'))->first();

        // Unknown space and missing grant are deliberately indistinguishable.
        if ($space === null || ! $space->canRead($this->agent())) {
            return Response::error('Shared space not found.');
        }

        try {
            $file = SharedFile::fileFor($space, $request->get('path'));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        if ($file === null) {
            return Response::error('File not found.');
        }

        if ($file->isBinary()) {
            if ($request->get('format') === 'base64') {
                return $this->jsonResponse([
                    'path' => $file->path,
                    'mime' => $file->mime,
                    'content_base64' => base64_encode($file->bytes()),
                ]);
            }

            if (str_starts_with($file->mime, 'image/')) {
                return Response::image($file->bytes(), $file->mime);
            }

            return Response::error('Binary file ('.$file->mime.') — call again with format=base64, or GET /api/shared/{space}/files/{path} for raw bytes.');
        }

        return Response::text($file->bytes());
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
            'format' => $schema->string()->description('Pass "base64" to read a non-image binary file — the result is a JSON payload with path, mime, and content_base64.'),
            ...$this->personaSchema($schema),
        ];
    }
}
