<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the calling agent from a Bearer key.
 *
 * Missing, malformed, unknown, or disabled keys all fail closed with 401;
 * the resolved agent is stored on the request's attributes so MCP tools can
 * scope every operation to it.
 */
class ResolveAgentFromKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $agent = $token !== null ? Agent::forKey($token) : null;

        if ($agent === null) {
            return response()->json(
                ['error' => 'Unauthorized'],
                401,
                ['WWW-Authenticate' => 'Bearer realm="melfina-mcp"'],
            );
        }

        $request->attributes->set('agent', $agent);

        return $next($request);
    }
}
