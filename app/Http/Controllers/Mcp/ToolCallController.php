<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\ToolCatalog;
use App\Models\AgentApiKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response as McpResponse;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * The HTTP fallback adapter: exposes every catalogued MCP tool as plain
 * JSON — `GET /api/tools` lists the surface (persona-less on a fleet
 * key), `POST /api/tools/{tool}` invokes it with the request body as the
 * tool's arguments object, the same shape MCP `tools/call` takes in
 * `params.arguments`.
 *
 * Dispatch is the tool class itself — validation, business logic, and
 * output all live in the MCP tool, so the two adapters can never drift.
 */
class ToolCallController extends Controller
{
    /**
     * The tool listing: names, descriptions, input schemas, and the scope
     * a scoped API key needs for each — the discovery surface skills and
     * curious runtimes read before calling.
     */
    public function index(ToolCatalog $catalog): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'tools' => collect($catalog->all())->map(fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
                'outputSchema' => $tool['outputSchema'],
                'scope' => $tool['scope'],
            ])->all(),
        ]);
    }

    /**
     * Invoke one tool. `agent` on the request comes from ResolveApiAgent;
     * a scoped key additionally carries `api_key` for the scope check.
     */
    public function __invoke(Request $request, ToolCatalog $catalog, string $tool): JsonResponse
    {
        $descriptor = $catalog->find($tool);

        if ($descriptor === null) {
            return $this->fail('Unknown tool.', 404);
        }

        if ($request->attributes->get('agent') === null) {
            return $this->fail('Agent persona required.', 401);
        }

        $apiKey = $request->attributes->get('api_key');

        if ($apiKey instanceof AgentApiKey && ! $apiKey->allows($descriptor['scope'])) {
            return $this->fail("This key lacks the {$descriptor['scope']} scope.", 403);
        }

        $arguments = $request->json()->all();

        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return $this->fail('Request body must be a JSON object of tool arguments.', 422);
        }

        // The MCP resolving callback copies arguments/meta off the
        // 'mcp.request' binding — binding one here makes container-called
        // tool handlers see the HTTP body as their Laravel\Mcp\Request.
        $container = Container::getInstance();
        $container->instance('mcp.request', new McpRequest($arguments));

        try {
            $result = $container->call([$descriptor['tool'], 'handle']);
        } catch (ValidationException $e) {
            return $this->fail('Invalid arguments.', 422, ['fields' => $e->errors()]);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (AuthenticationException $e) {
            return $this->fail($e->getMessage(), 401);
        } catch (AuthorizationException $e) {
            return $this->fail($e->getMessage(), 403);
        } finally {
            $container->forgetInstance('mcp.request');
        }

        return $this->respond($result, $descriptor);
    }

    /**
     * Fold the tool's MCP response(s) into the HTTP contract:
     * `{ok: true, result}` on success, `{ok: false, error}` on a tool
     * error. `result` is the decoded JSON payload our tools emit as text;
     * a non-JSON text stays a string, and multi-part results keep their
     * MCP content shape under `result.content`.
     *
     * @param  array{name: string, tool: Tool}  $descriptor
     */
    private function respond(mixed $result, array $descriptor): JsonResponse
    {
        $responses = $this->responses($result);

        $error = $responses->first(fn (McpResponse $response): bool => $response->isError());

        if ($error !== null) {
            return $this->fail((string) $error->content(), 400);
        }

        $parts = $responses->map(fn (McpResponse $response): array => $response->content()->toTool($descriptor['tool']));

        $payload = $parts->count() === 1 && ($parts->first()['type'] ?? null) === 'text'
            ? $this->decode($parts->first()['text'] ?? '')
            : ['content' => $parts->all()];

        return response()->json(['ok' => true, 'result' => $payload]);
    }

    /**
     * @return Collection<int, McpResponse>
     */
    private function responses(mixed $result): Collection
    {
        $wrap = fn (mixed $item): array => match (true) {
            $item instanceof McpResponse => [$item],
            $item instanceof ResponseFactory => $item->responses()->all(),
            is_string($item) => [McpResponse::text($item)],
            default => [],
        };

        return collect(is_iterable($result) ? $result : [$result])
            ->flatMap($wrap)
            ->values();
    }

    private function decode(string $text): mixed
    {
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function fail(string $error, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $error, ...$extra], $status);
    }
}
