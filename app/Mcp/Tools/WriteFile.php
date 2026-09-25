<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\VfsFile;
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
#[Description('Write a file to your private file tree (overwrites existing content).')]
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
            'content' => 'required|string',
        ]);

        $agent = $this->agent();

        $rateKey = 'mcp-write:'.$agent->id;

        if (RateLimiter::tooManyAttempts($rateKey, self::WRITE_LIMIT_PER_MINUTE)) {
            return Response::error('Write rate limit exceeded. Try again in '.RateLimiter::availableIn($rateKey).' seconds.');
        }

        try {
            VfsFile::writeFor($agent, $request->get('path'), $request->get('content'));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        RateLimiter::hit($rateKey, 60);

        return Response::text('File written.');
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
            'content' => $schema->string()->description('Full text content to store (replaces existing).')->required(),
        ];
    }
}
