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
        // The env-provided baseline is captured once per app instance —
        // config()->set mutates the repo, so without it a domain removed in
        // the console would linger in the merged list for the instance's life.
        if (! config()->has('melfina.redirect_domain_baseline')) {
            config()->set('melfina.redirect_domain_baseline', config('mcp.redirect_domains', []));
        }

        $stored = array_filter(
            User::query()->where('is_owner', true)->first()?->mcp_redirect_domains ?? [],
            'is_string',
        );

        config()->set('mcp.redirect_domains', array_values(array_unique(array_merge(
            config('melfina.redirect_domain_baseline'),
            $stored,
        ))));

        return $next($request);
    }
}
