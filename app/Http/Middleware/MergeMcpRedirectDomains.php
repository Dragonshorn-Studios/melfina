<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merges the owner's console-managed OAuth redirect origins
 * (users.mcp_redirect_domains) into config('mcp.redirect_domains') before
 * DCR validation runs, so new client origins are added from the MCP
 * console page instead of an env edit + redeploy.
 */
class MergeMcpRedirectDomains
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // The env-provided baseline lives in its own never-mutated key:
        // config()->set on 'redirect_domains' persists for the app
        // instance, so a console-removed origin must not linger there.
        $stored = array_filter(
            User::query()->where('is_owner', true)->first()?->mcp_redirect_domains ?? [],
            'is_string',
        );

        config()->set('mcp.redirect_domains', array_values(array_unique(array_merge(
            config('mcp.redirect_domains_env', []),
            $stored,
        ))));

        return $next($request);
    }
}
