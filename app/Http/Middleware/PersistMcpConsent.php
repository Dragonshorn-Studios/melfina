<?php

namespace App\Http\Middleware;

use App\Mcp\Auth\StoreMcpConsent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs in the web group; only acts on the OAuth approve request so the
 * owner's agent pick is persisted before the auth code is issued.
 */
class PersistMcpConsent
{
    public function __construct(private StoreMcpConsent $store) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('passport.authorizations.approve')) {
            $this->store->handle($request);
        }

        return $next($request);
    }
}
