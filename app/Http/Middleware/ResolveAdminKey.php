<?php

namespace App\Http\Middleware;

use App\Models\AdminApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the provisioning surface (/api/admin) — the
 * machine-to-machine API a fleet system such as Yukihime drives with an
 * owner-minted admin key. Bearer token or HTTP Basic password carries the
 * `melfa_…` credential; the resolved key rides on the request as
 * `admin_key` and the controller enforces its permissions.
 *
 * Nothing else accepts this prefix: agents keep authenticating to /mcp
 * and /api/tools with their own `melf_`/`melfs_` keys (or a fleet key),
 * so an admin credential can manage and inspect brains but never call
 * tools or resolve to an agent persona. Fails closed.
 */
class ResolveAdminKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            $token = $request->getPassword();
        }

        if (is_string($token) && $token !== '') {
            $key = AdminApiKey::forKey($token);

            if ($key !== null) {
                $request->attributes->set('admin_key', $key);

                return $next($request);
            }
        }

        return response()->json(
            ['ok' => false, 'error' => 'Unauthorized'],
            401,
            ['WWW-Authenticate' => 'Bearer realm="melfina-admin"'],
        );
    }
}
