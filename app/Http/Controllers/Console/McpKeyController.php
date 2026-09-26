<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Mcp\PluginRepo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class McpKeyController extends Controller
{
    /**
     * Rotate the install-wide fleet key: stores only the new hash and
     * flashes the plaintext once. The old key stops resolving immediately —
     * and cached plugin repos embedding it are dropped.
     */
    public function store(Request $request, PluginRepo $repos): RedirectResponse
    {
        $plaintext = $request->user()->rotateMcpKey();

        // Cleanup is a side effect — a filesystem failure must not eat the
        // shown-once plaintext (same contract as agent key rotation).
        try {
            $repos->dropAllRepos();
        } catch (\Throwable $e) {
            Log::warning('plugin repo cleanup failed', ['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('console.dashboard')
            ->with('plaintext_mcp_key', $plaintext);
    }
}
