<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\PluginRepo;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the per-agent plugin repository over git's dumb HTTP protocol.
 *
 * Credentials arrive as HTTP Basic auth — git folds the `user:pass@host`
 * userinfo in a clone URL into an Authorization header on retry, so keys
 * stay out of request-path logs:
 *
 *     https://{persona}:{fleetKey}@host/plugins/anything.git
 *     https://{persona}:{agentKey}@host/plugins/anything.git
 *     https://melfina:{fleetKey}@host/plugins/~melfina.git
 *
 * The username picks the persona (agent name or `melf_…` key) in fleet
 * mode and is cosmetic in direct mode; the path segment is decorative —
 * except `~melfina.git`, which serves the fleet-wide package gated only by
 * the fleet key. The `~` prefix can never collide with a per-agent URL:
 * those are built from `Str::slug($agent->name)`, and slugs never contain
 * `~`. Missing credentials get a Basic challenge, dead ones a flat 404.
 */
class PluginRepoController extends Controller
{
    public function show(Request $request, PluginRepo $repos, string $repo, string $path = ''): Response
    {
        $user = $request->getUser();
        $password = $request->getPassword();

        if (! is_string($password) || $password === '') {
            return response('', 401, ['WWW-Authenticate' => 'Basic realm="melfina-plugins"']);
        }

        // The fleet-wide package: one repo for the whole install, keyed
        // only by the fleet credential — each agent fills in its own name.
        // `~melfina.git` is a reserved path — no agent slug can produce it.
        if ($repo === '~melfina.git' && User::isMcpKey($password)) {
            try {
                $dir = $repos->ensureGlobal($password);
            } catch (\Throwable) {
                abort(500);
            }

            return $this->sendFile($repos, $dir, $path);
        }

        [$agent, $mode] = $this->resolve($user, $password);

        if ($agent === null) {
            abort(404);
        }

        try {
            $dir = $repos->ensureBuilt($agent, $mode, $password);
        } catch (\Throwable) {
            abort(500);
        }

        return $this->sendFile($repos, $dir, $path);
    }

    private function sendFile(PluginRepo $repos, string $dir, string $path): Response
    {
        $file = $repos->file($dir, $path === '' ? 'info/refs' : $path);

        if ($file === null) {
            abort(404);
        }

        return response()->file($file, ['Content-Type' => 'application/octet-stream']);
    }

    /**
     * @return array{0: ?Agent, 1: string}
     */
    private function resolve(?string $user, string $password): array
    {
        if (User::isMcpKey($password)) {
            // Fleet credential — the username picks the persona.
            return [is_string($user) ? Agent::forPersona($user) : null, 'fleet'];
        }

        // The password is a live agent key — the repo embeds it as the
        // Bearer credential and the package is persona-independent.
        return [Agent::forKey($password), 'direct'];
    }
}
