<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Mcp\PluginRepo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $repos->dropAllRepos();

        return redirect()
            ->route('console.dashboard')
            ->with('plaintext_mcp_key', $plaintext);
    }
}
