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

#[Name('read_file_binary')]
#[Description('Read a binary file from your private file tree. Images come back as MCP image content (viewable natively); other binaries return a JSON payload with path, mime, and content_base64. For large downloads prefer `GET /api/files/{path}` — raw bytes, no base64. Text files belong on `read_file`.')]
class ReadFileBinary extends Tool
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
            $file = VfsFile::fileFor($this->agent(), $request->get('path'));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        if ($file === null) {
            return Response::error('File not found.');
        }

        if (! $file->isBinary()) {
            return Response::error('Text file — use read_file instead.');
        }

        if (str_starts_with($file->mime, 'image/')) {
            return Response::image($file->bytes(), $file->mime);
        }

        return $this->jsonResponse([
            'path' => $file->path,
            'mime' => $file->mime,
            'content_base64' => base64_encode($file->bytes()),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Path of the binary file to read, e.g. "img/logo.png".')->required(),
            ...$this->personaSchema($schema),
        ];
    }
}
