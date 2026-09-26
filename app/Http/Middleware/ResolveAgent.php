<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Models\McpTokenAgent;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the calling agent from three auth paths:
 *
 * - fleet key Bearer (`melfg_…`, User::isMcpKey) plus the agent's own key in
 *   the `X-Melfina-Agent-Key` header or `agent_key` query param — the shape
 *   shared MCP configs use, where the config never holds a persona;
 * - a per-agent Bearer key (Agent::forKey) for dedicated configs;
 * - an OAuth access token bound to an agent through the consent grant
 *   (McpTokenAgent).
 *
 * All paths fail closed with 401; the resolved agent is stored on the
 * request's attributes for MCP tools.
 */
class ResolveAgent
{
    public const AGENT_KEY_HEADER = 'X-Melfina-Agent-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token !== null && User::isMcpKey($token)) {
            return $this->handleFleetRequest($request, $next);
        }

        $agent = $token !== null ? Agent::forKey($token) : null;
        $agent ??= $this->agentFromOAuthToken();

        if ($agent === null) {
            return $this->unauthorized();
        }

        $request->attributes->set('agent', $agent);

        return $next($request);
    }

    /**
     * Fleet-mode request: the fleet key only opens the endpoint. The persona
     * comes from the key the agent stores itself — without one there is no
     * agent to scope to, so the request fails closed.
     */
    private function handleFleetRequest(Request $request, Closure $next): Response
    {
        $agentKey = $request->header(self::AGENT_KEY_HEADER) ?? $request->query('agent_key');

        $agent = is_string($agentKey) ? Agent::forKey($agentKey) : null;

        if ($agent === null) {
            return $this->unauthorized('agent key required');
        }

        $request->attributes->set('agent', $agent);

        return $next($request);
    }

    private function unauthorized(string $error = 'Unauthorized'): Response
    {
        return response()->json(
            ['error' => $error],
            401,
            ['WWW-Authenticate' => 'Bearer realm="melfina-mcp"'],
        );
    }

    private function agentFromOAuthToken(): ?Agent
    {
        // Missing Passport keys or malformed tokens raise inside the guard;
        // an unresolvable credential is a 401, never a 500.
        try {
            $user = Auth::guard('api')->user();
        } catch (\Throwable) {
            return null;
        }

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
