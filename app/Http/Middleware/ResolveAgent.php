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
 * - fleet key Bearer (`melfg_…`, User::isMcpKey) plus the agent's persona —
 *   its name or `melf_…` key — in the `X-Melfina-Agent-Key` header or
 *   `agent_key` query param, for shared MCP configs that hold no persona;
 *   URL-only configs can instead carry both as HTTP Basic
 *   (`https://{name}:{fleet}@host/mcp` → username is the persona);
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

        // Basic-auth form for clients that can only set a URL:
        // https://{persona}:{fleet-key}@host/mcp — the password proves
        // fleet access, the username is the persona. A dedicated agent key
        // as the password works too and needs no username, mirroring the
        // plugin clone surface.
        $basicUser = $request->getUser();
        $basicPass = $request->getPassword();

        if (is_string($basicPass) && $basicPass !== '') {
            if ($basicUser !== null && $basicUser !== '' && User::isMcpKey($basicPass)) {
                return $this->handleFleetRequest($request, $next, $basicUser);
            }

            $agent = Agent::forKey($basicPass);

            if ($agent !== null) {
                $request->attributes->set('agent', $agent);

                return $next($request);
            }
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
     * comes from what the agent knows about itself — its name or key —
     * without one there is no agent to scope to, so the request fails closed.
     */
    private function handleFleetRequest(Request $request, Closure $next, ?string $basicPersona = null): Response
    {
        $persona = $basicPersona ?? $request->header(self::AGENT_KEY_HEADER);

        if ($persona === null) {
            $candidate = $request->query('agent_key');

            // Query strings land in access logs — accept only the non-secret
            // name form there; keys belong in the header.
            $persona = is_string($candidate) && ! str_starts_with($candidate, 'melf_')
                ? $candidate
                : null;
        }

        $agent = is_string($persona) ? Agent::forPersona($persona) : null;

        if ($agent === null) {
            return $this->unauthorized('agent persona required');
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
