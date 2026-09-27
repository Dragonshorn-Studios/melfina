<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\Agent;
use App\Models\VfsFile;
use App\Support\FileMime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('write_file')]
#[Description('Write a file to your private file tree (overwrites existing content). Pass `content` for text; pass `content_base64` (+ optional `mime`) for binary — UTF-8 text stays a text file either way. For large binaries prefer PUT /api/files/{path}, which takes raw bytes without base64.')]
class WriteFile extends Tool
{
    use ResolvesAgent;

    /**
     * Writes allowed per minute per agent.
     */
    public const WRITE_LIMIT_PER_MINUTE = 60;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'path' => 'required|string',
            'content' => 'required_without:content_base64|nullable|string',
            'content_base64' => 'required_without:content|nullable|string',
            'mime' => 'nullable|string|max:255',
        ]);

        $agent = $this->agent();

        $rateKey = 'mcp-write:'.$agent->id;

        if (RateLimiter::tooManyAttempts($rateKey, self::WRITE_LIMIT_PER_MINUTE)) {
            return Response::error('Write rate limit exceeded. Try again in '.RateLimiter::availableIn($rateKey).' seconds.');
        }

        try {
            $file = $this->write($agent, $request);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        RateLimiter::hit($rateKey, 60);

        return Response::text('File written ('.$file->byteSize().' bytes'.($file->isBinary() ? ', '.$file->mime : '').').');
    }

    /**
     * Text arg → text row; base64 arg → bytes through the same
     * textual/binary split the raw PUT endpoint applies.
     *
     * @throws InvalidArgumentException
     */
    private function write(Agent $agent, Request $request): VfsFile
    {
        $path = $request->get('path');

        $encoded = $request->get('content_base64');

        if ($encoded === null) {
            return VfsFile::writeFor($agent, $path, $request->get('content'));
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false) {
            throw new InvalidArgumentException('content_base64 is not valid base64.');
        }

        $mime = FileMime::normalize($request->get('mime'));

        return FileMime::isTextual($bytes, $mime)
            ? VfsFile::writeFor($agent, $path, $bytes)
            : VfsFile::writeBinaryFor($agent, $path, $bytes, $mime);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Path of the file to write, e.g. "notes/todo.txt".')->required(),
            'content' => $schema->string()->description('Full text content to store (replaces existing). Required unless content_base64 is passed.'),
            'content_base64' => $schema->string()->description('Binary content, base64-encoded (replaces existing). Alternative to content for non-text files; bytes over ~1 MB belong on PUT /api/files/{path} instead.'),
            'mime' => $schema->string()->description('Declared content type for content_base64, e.g. "image/png". The stored mime always comes from sniffing the actual bytes.'),
            ...$this->personaSchema($schema),
        ];
    }
}
