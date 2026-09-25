<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Models\McpTokenAgent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the calling agent from either auth path: a static Bearer key
 * (Agent::forKey) or an OAuth access token bound to an agent through the
 * consent grant (McpTokenAgent). Both fail closed with 401; the resolved
 * agent is stored on the request's attributes for MCP tools.
 */
class ResolveAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $agent = $token !== null ? Agent::forKey($token) : null;
        $agent ??= $this->agentFromOAuthToken();

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

    private function agentFromOAuthToken(): ?Agent
    {
        $user = Auth::guard('api')->user();

        if ($user === null) {
            return null;
        }

        // The guard wraps the validated JWT in an AccessToken value object:
        // oauth_access_token_id holds the Passport jti our bindings key on.
        $token = $user->token();
        $jti = $token?->oauth_access_token_id;

        if (! is_string($jti) || ! $token->can('mcp:use')) {
            return null;
        }

        return McpTokenAgent::query()
            ->where('token_id', $jti)
            ->first()?->agent;
    }
}
