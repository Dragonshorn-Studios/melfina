<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Models\AgentApiKey;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the calling agent for the HTTP tool fallback
 * (/api/tools/{tool}) — the plain-JSON adapter for runtimes that cannot
 * install an MCP server. Mirrors ResolveAgent's credential surface:
 *
 * - a scoped API key (`melfs_…`, AgentApiKey) — the resolved key rides on
 *   the request as `api_key` and the endpoint enforces its scopes;
 * - a per-agent Bearer key (`melf_…`, Agent::forKey);
 * - a fleet key (`melfg_…`) plus the agent's persona — its name or
 *   `melf_…` key — in `X-Melfina-Agent-Key`, the `agent_key` query param
 *   (names only; keys stay out of logged URLs), or the `name` argument
 *   in the JSON body, for clients that can't set headers;
 * - the same credentials as HTTP Basic (`https://{persona}:{key}@host/…`)
 *   for URL-only configs — password is the credential, username the
 *   persona for fleet keys, exactly like /mcp and the plugin repos.
 *
 * Credential precedence matches /mcp: Bearer fleet > Basic > Bearer
 * key. All paths fail closed; the resolved agent lands on the request's
 * `agent` attribute, a persona-less fleet key lands as `fleet`.
 */
class ResolveApiAgent
{
    public const AGENT_KEY_HEADER = 'X-Melfina-Agent-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (is_string($token) && $token !== '' && User::isMcpKey($token)) {
            return $this->handleFleet($request, $next);
        }

        // Basic-auth form for clients that can only set a URL. It preempts
        // a Bearer agent key the same way it does on /mcp.
        $basicPass = $request->getPassword();

        if (is_string($basicPass) && $basicPass !== '') {
            if (User::isMcpKey($basicPass)) {
                $basicUser = $request->getUser();

                return $this->handleFleet($request, $next, is_string($basicUser) && $basicUser !== '' ? $basicUser : null);
            }

            $resolved = $this->resolveKeyCredential($basicPass);

            if ($resolved !== null) {
                return $this->resolved($request, $next, ...$resolved);
            }
        }

        if (is_string($token) && $token !== '') {
            $resolved = $this->resolveKeyCredential($token);

            if ($resolved !== null) {
                return $this->resolved($request, $next, ...$resolved);
            }
        }

        return $this->unauthorized();
    }

    /**
     * @return array{0: Agent, 1: ?AgentApiKey}|null
     */
    private function resolveKeyCredential(string $credential): ?array
    {
        if (str_starts_with($credential, AgentApiKey::PREFIX)) {
            $key = AgentApiKey::forKey($credential);

            return $key !== null ? [$key->agent, $key] : null;
        }

        $agent = Agent::forKey($credential);

        return $agent !== null ? [$agent, null] : null;
    }

    /**
     * Fleet-key request: the key opens the endpoint, the persona claims
     * the agent. A persona-less fleet key gets the `fleet` attribute —
     * listing endpoints may answer it, tool calls require an agent.
     */
    private function handleFleet(Request $request, Closure $next, ?string $persona = null): Response
    {
        $persona ??= $request->header(self::AGENT_KEY_HEADER);

        if ($persona === null) {
            $candidate = $request->query('agent_key');

            $persona = is_string($candidate) && ! str_starts_with($candidate, 'melf_')
                ? $candidate
                : null;
        }

        // Last resort for clients that can't vary headers: the imp names
        // itself inside the call — `name` is an argument on every tool.
        $persona ??= $this->personaFromBody($request);

        if (! is_string($persona)) {
            $request->attributes->set('fleet', true);

            return $next($request);
        }

        $agent = Agent::forPersona($persona);

        if ($agent === null) {
            return $this->unauthorized('agent persona required');
        }

        $request->attributes->set('fleet', true);
        $request->attributes->set('agent', $agent);

        return $next($request);
    }

    /**
     * On a dedicated credential the `name` argument — when sent — is a
     * claim, not a selector: it must agree with the resolved agent, same
     * as on /mcp. A mismatched name is a misconfigured bot, not another
     * agent.
     *
     * @param  array{0: Agent, 1: ?AgentApiKey}  $resolved
     */
    private function resolved(Request $request, Closure $next, Agent $agent, ?AgentApiKey $apiKey): Response
    {
        $claimed = $this->personaFromBody($request);

        if ($claimed !== null && Agent::forPersona($claimed)?->id !== $agent->id) {
            return $this->unauthorized('persona does not match the key');
        }

        $request->attributes->set('agent', $agent);

        if ($apiKey !== null) {
            $request->attributes->set('api_key', $apiKey);
        }

        return $next($request);
    }

    /**
     * The `name` body field is the persona channel shared with the MCP
     * `arguments.name` convention.
     */
    private function personaFromBody(Request $request): ?string
    {
        $name = $request->json('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function unauthorized(string $error = 'Unauthorized'): Response
    {
        return response()->json(
            ['ok' => false, 'error' => $error],
            401,
            ['WWW-Authenticate' => 'Bearer realm="melfina-tools"'],
        );
    }
}
