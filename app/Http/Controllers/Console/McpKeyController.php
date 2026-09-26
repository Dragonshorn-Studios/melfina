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
            // Packages bake config('app.url'), not the request URL — flag
            // when what they embed resolves to a host remote agents
            // can't reach.
            'endpointIsLocal' => in_array(
                parse_url(rtrim((string) config('app.url'), '/'), PHP_URL_HOST),
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
            // Env-provided origins (uneditable here — they come from
            // MCP_REDIRECT_DOMAINS) vs. the owner's console-managed ones.
            'envRedirectDomains' => config('mcp.redirect_domains', []),
            'storedRedirectDomains' => $request->user()->mcp_redirect_domains ?? [],
        ]);
    }

    /**
     * Allow a new OAuth redirect origin (scheme://host[:port]) for Dynamic
     * Client Registration — the console equivalent of appending to
     * MCP_REDIRECT_DOMAINS. Persisted on the owner row; merged into the
     * allow-list by MergeMcpRedirectDomains on the oauth routes.
     */
    public function storeRedirectDomain(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255', function (string $attribute, string $value, \Closure $fail): void {
                if (self::normalizeRedirectOrigin($value) === null) {
                    $fail('Must be an origin like https://app.example.com — http(s) scheme, host, optional port, no path.');
                }
            }],
        ]);

        $origin = self::normalizeRedirectOrigin($validated['domain']);
        $user = $request->user();

        $domains = $user->mcp_redirect_domains ?? [];
        if (! in_array($origin, $domains, true)) {
            $domains[] = $origin;
            $user->mcp_redirect_domains = $domains;
            $user->save();
        }

        return redirect()
            ->route('console.mcp.connect')
            ->with('status', "Redirect origin {$origin} allowed.");
    }

    /**
     * Remove a console-managed redirect origin. Env-provided origins can't
     * be removed here — they follow MCP_REDIRECT_DOMAINS.
     */
    public function destroyRedirectDomain(Request $request): RedirectResponse
    {
        $validated = $request->validate(['domain' => ['required', 'string', 'max:255']]);
        $user = $request->user();

        $user->mcp_redirect_domains = array_values(array_filter(
            $user->mcp_redirect_domains ?? [],
            fn (string $domain): bool => $domain !== $validated['domain'],
        ));
        $user->save();

        return redirect()
            ->route('console.mcp.connect')
            ->with('status', "Redirect origin {$validated['domain']} removed.");
    }

    /**
     * Normalize to a bare origin — scheme://host[:port] — or null when the
     * input carries a path, query, fragment, userinfo, wildcard, or a
     * non-http(s) scheme (custom-scheme callbacks live in
     * mcp.custom_schemes, a separate allow-list).
     */
    private static function normalizeRedirectOrigin(string $input): ?string
    {
        // Trailing slashes collapse — 'https://x/' and 'https://x' are the
        // same origin.
        $input = rtrim(trim($input), '/');

        if ($input === '' || str_contains($input, '*')) {
            return null;
        }

        $parts = parse_url($input);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        if (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $scheme.'://'.strtolower($host).(isset($parts['port']) ? ':'.$parts['port'] : '');
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
            $zipPath = $repos->publicPackageZip();
        } catch (\RuntimeException $e) {
            abort(500, $e->getMessage());
        }

        return response()->download($zipPath, 'melfina-fleet.zip')->deleteFileAfterSend();
    }
}
