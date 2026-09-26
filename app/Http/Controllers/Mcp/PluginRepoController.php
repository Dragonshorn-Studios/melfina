<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\PluginRepo;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the per-agent plugin repository over git's dumb HTTP protocol.
 * The credentials ride inside the clone URL — /plugins/{fleet}/{agent}.git
 * for the fleet-key form, /plugins/{agent}.git for the direct form — so the
 * repo materializes only for whoever already holds them. Unknown or stale
 * credentials get a flat 404.
 */
class PluginRepoController extends Controller
{
    public function show(PluginRepo $repos, string $repo, string $path = ''): Response
    {
        return $this->serve($repos, $repo, $path, null);
    }

    public function showFleet(PluginRepo $repos, string $fleet, string $repo, string $path = ''): Response
    {
        return $this->serve($repos, $repo, $path, $fleet);
    }

    private function serve(PluginRepo $repos, string $repo, string $path, ?string $fleet): Response
    {
        $persona = Str::beforeLast($repo, '.git');
        $agent = Agent::forPersona($persona);

        // The direct form embeds the URL segment as the Bearer credential,
        // so it must be a real `melf_…` key — a bare name can't open /mcp on
        // its own. A fleet key in the URL must be live.
        if (
            $agent === null
            || ($fleet === null && ! str_starts_with($persona, 'melf_'))
            || ($fleet !== null && ! User::isMcpKey($fleet))
        ) {
            abort(404);
        }

        try {
            $dir = $repos->ensureBuilt($agent, $persona, $fleet);
        } catch (\Throwable) {
            abort(500);
        }

        $file = $repos->file($dir, $path === '' ? 'info/refs' : $path);

        if ($file === null) {
            abort(404);
        }

        return response()->file($file, ['Content-Type' => 'application/octet-stream']);
    }
}
