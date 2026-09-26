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
 *   (`https://{name}:{fleet}@host/mcp` → username is the persona — it
 *   wins over the header/query forms when both are sent);
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
        // fleet access, the username is the persona. An empty username
        // falls back to the header/query persona, exactly like a fleet
        // Bearer. A dedicated agent key as the password works too and
        // needs no username, mirroring the plugin clone surface.
        $basicUser = $request->getUser();
        $basicPass = $request->getPassword();

        if (is_string($basicPass) && $basicPass !== '') {
            if (User::isMcpKey($basicPass)) {
                return $this->handleFleetRequest($request, $next, $basicUser !== '' ? $basicUser : null);
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

        // A `name` argument on a dedicated key must agree with it — a
        // mismatched name is a misconfigured bot, not another agent.
        $claimed = $this->personaFromToolArguments($request);

        if ($claimed !== null && Agent::forPersona($claimed)?->id !== $agent->id) {
            return $this->unauthorized('persona does not match the key');
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

        // Last resort for shared clients that can't vary headers at all:
        // the imp names itself inside the call — `params.arguments.name`.
        $persona ??= $this->personaFromToolArguments($request);

        $agent = is_string($persona) ? Agent::forPersona($persona) : null;

        if ($agent === null) {
            return $this->unauthorized('agent persona required');
        }

        $request->attributes->set('agent', $agent);

        return $next($request);
    }

    /**
     * The `name` tool argument is an alternate persona channel for shared
     * fleet configs (grokbot-style: one credential, many imps that only
     * know their own names). Only read on tools/call.
     */
    private function personaFromToolArguments(Request $request): ?string
    {
        if ($request->input('method') !== 'tools/call') {
            return null;
        }

        $name = $request->input('params.arguments.name');

        return is_string($name) && $name !== '' ? $name : null;
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
