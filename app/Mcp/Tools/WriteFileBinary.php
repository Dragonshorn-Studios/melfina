<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\VfsFile;
use App\Support\FileMime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('write_file_binary')]
#[Description('Write a binary file (image, PDF, any non-text bytes) to your private file tree. Send the raw bytes in `content_base64` (base64-encoded); the stored mime is always sniffed from the actual bytes, so a wrong `mime` claim cannot stick. UTF-8 text written this way still lands as a normal text file. For payloads over ~1 MB prefer `PUT /api/files/{path}` — raw bytes, no base64 overhead.')]
class WriteFileBinary extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'path' => 'required|string',
            'content_base64' => 'required|string',
            'mime' => 'nullable|string|max:255',
        ]);

        $bytes = base64_decode($request->get('content_base64'), true);

        if ($bytes === false) {
            return Response::error('content_base64 is not valid base64.');
        }

        $agent = $this->agent();

        $rateKey = 'mcp-write:'.$agent->id;

        if (RateLimiter::tooManyAttempts($rateKey, WriteFile::WRITE_LIMIT_PER_MINUTE)) {
            return Response::error('Write rate limit exceeded. Try again in '.RateLimiter::availableIn($rateKey).' seconds.');
        }

        $mime = FileMime::normalize($request->get('mime'));

        try {
            $file = FileMime::isTextual($bytes, $mime)
                ? VfsFile::writeFor($agent, $request->get('path'), $bytes)
                : VfsFile::writeBinaryFor($agent, $request->get('path'), $bytes, $mime);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        RateLimiter::hit($rateKey, 60);

        return Response::text('File written ('.$file->byteSize().' bytes'.($file->isBinary() ? ', '.$file->mime : '').').');
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Path of the file to write, e.g. "img/logo.png".')->required(),
            'content_base64' => $schema->string()->description('Raw file bytes, base64-encoded (replaces existing). Bytes over ~1 MB belong on PUT /api/files/{path} instead.')->required(),
            'mime' => $schema->string()->description('Declared content type, e.g. "image/png". The stored mime always comes from sniffing the actual bytes.'),
            ...$this->personaSchema($schema),
        ];
    }
}
