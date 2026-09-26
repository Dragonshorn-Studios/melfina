<?php

namespace App\Mcp\Concerns;

use App\Http\Middleware\ResolveAgent;
use App\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use JsonException;
use Laravel\Mcp\Response;

/**
 * The agent placed on the request by {@see ResolveAgent}.
 */
trait ResolvesAgent
{
    /**
     * The persona argument shared by every tool — how an imp names itself
     * when the connection carries a shared fleet key. Declared in the
     * schema so agents discover it from the tool listing.
     *
     * @return array<string, Type>
     */
    protected function personaSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Your agent name — your persona on this install. Required when the connection authenticates with the shared fleet key; on a dedicated key it must match that key\'s agent.'),
        ];
    }

    protected function agent(): Agent
    {
        $agent = request()->attributes->get('agent');

        assert($agent instanceof Agent);

        return $agent;
    }

    /**
     * Encode a payload as a text response — failing loudly rather than
     * returning an empty body when the data can't be represented as JSON
     * (e.g. non-UTF-8 attachment content).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function jsonResponse(array $payload): Response
    {
        try {
            return Response::text(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return Response::error('Failed to encode the response.');
        }
    }
}
