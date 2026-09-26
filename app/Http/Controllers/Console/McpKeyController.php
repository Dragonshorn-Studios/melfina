<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Mcp\PluginRepo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class McpKeyController extends Controller
{
    /**
     * The fleet-wide connect guide: one MCP install for every agent — the
     * persona-free plugin repo plus the URL forms a client can paste.
     */
    public function connect(Request $request, PluginRepo $repos): View
    {
        $endpoint = url('/mcp');

        return view('console.mcp.connect', [
            'endpoint' => $endpoint,
            // The endpoint is baked into generated packages — flag when it
            // resolves to a host remote agents can't reach.
            'endpointIsLocal' => in_array(
                parse_url($endpoint, PHP_URL_HOST),
                ['localhost', '127.0.0.1', '::1', '[::1]'],
                true
            ),
            'hasFleetKey' => $request->user()->mcp_key_hash !== null,
            // Public, credential-free package at an unguessable derived name
            // — for importers that never send Basic credentials.
            'publicRepoUrl' => url('/plugins/'.$repos->publicRepoName()),
            // Credentials travel as HTTP Basic userinfo so they stay out of
            // request-path logs. '${1}' braces guard digit-leading inserts.
            'fleetRepoUrl' => preg_replace('#^(https?://)#', '${1}melfina:melfg_…@', url('/plugins/~melfina.git')),
            'personaUrl' => preg_replace('#^(https?://)#', '${1}NAME:melfg_…@', $endpoint),
            'headersConfig' => json_encode([
                'mcpServers' => [
                    'melfina' => [
                        'type' => 'streamable-http',
                        'url' => $endpoint,
                        'headers' => [
                            'Authorization' => 'Bearer melfg_…',
                            'X-Melfina-Agent-Key' => 'NAME',
                        ],
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

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

    /**
     * Download the credential-free fleet package as a ZIP — for importers
     * that take an upload rather than a git URL (e.g. MiniMax's plugin
     * submit refuses non-GitHub repos).
     */
    public function pluginZip(PluginRepo $repos): BinaryFileResponse
    {
        try {
            $zipPath = $repos->zipDirectory($repos->rebuildPublicPackage());
        } catch (\RuntimeException $e) {
            abort(500, $e->getMessage());
        }

        return response()->download($zipPath, 'melfina-fleet.zip')->deleteFileAfterSend();
    }
}
