<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\SharedFile;
use App\Models\SharedSpace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('write_shared')]
#[Description('Write a file into a shared space you have a write grant on (overwrites existing content).')]
class WriteShared extends Tool
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
            'content' => 'required|string',
        ]);

        $agent = $this->agent();

        $space = SharedSpace::query()->where('name', $request->get('space'))->first();

        // Unknown space and missing write grant are deliberately indistinguishable.
        if ($space === null || ! $space->canWrite($agent)) {
            return Response::error('Shared space not found.');
        }

        $rateKey = 'mcp-shared-write:'.$agent->id;

        if (RateLimiter::tooManyAttempts($rateKey, WriteFile::WRITE_LIMIT_PER_MINUTE)) {
            return Response::error('Write rate limit exceeded. Try again in '.RateLimiter::availableIn($rateKey).' seconds.');
        }

        RateLimiter::hit($rateKey, 60);

        try {
            SharedFile::writeFor($space, $request->get('path'), $request->get('content'));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

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
            'space' => $schema->string()->description('Shared space name.')->required(),
            'path' => $schema->string()->description('Path of the file inside the space.')->required(),
            'content' => $schema->string()->description('Full text content to store (replaces existing).')->required(),
        ];
    }
}
