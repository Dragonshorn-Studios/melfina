<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Mcp\PluginRepo;
use App\Models\McpFleetKey;
use App\Services\GitHubPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
            'hasFleetKey' => $request->user()->mcp_key_hash !== null
                || $request->user()->mcpFleetKeys()->whereNull('revoked_at')->exists(),
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
            // redirect_domains_env is the never-mutated env baseline;
            // 'redirect_domains' may already carry the merged console list.
            'envRedirectDomains' => config('mcp.redirect_domains_env', []),
            'storedRedirectDomains' => array_values(array_diff(
                $request->user()->mcp_redirect_domains ?? [],
                config('mcp.redirect_domains_env', []),
            )),
            // GitHub publish settings — the token itself is never rendered.
            'hasGitHubToken' => $request->user()->github_token !== null,
            'githubRepo' => $request->user()->github_repo,
        ]);
    }

    /**
     * Store the GitHub PAT + target repo used by publishGitHub(). The token
     * is Laravel-encrypted on the model and never echoed back to the page.
     */
    public function storeGitHub(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'github_token' => ['nullable', 'string', 'max:255'],
            'github_repo' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)?$#'],
        ]);

        $user = $request->user();
        $user->github_repo = trim($validated['github_repo'], " \t/");
        if (filled($validated['github_token'] ?? null)) {
            $user->github_token = $validated['github_token'];
        }
        $user->save();

        return redirect()
            ->route('console.mcp.connect')
            ->with('status', 'GitHub settings saved.');
    }

    /**
     * Push the credential-free fleet package to the owner's GitHub repo —
     * the importer-facing path for GitHub-only clients (MiniMax).
     */
    public function publishGitHub(Request $request, PluginRepo $repos, GitHubPublisher $github): RedirectResponse
    {
        $user = $request->user();

        if ($user->github_token === null || $user->github_repo === null) {
            throw ValidationException::withMessages([
                'github_repo' => 'Save a GitHub token and repo first.',
            ]);
        }

        try {
            $url = $github->publish($user->github_token, $user->github_repo, $repos->publicPackageFiles());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['github_repo' => $e->getMessage()]);
        }

        return redirect()
            ->route('console.mcp.connect')
            ->with('status', "Published to {$url}");
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
            'domain' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || self::normalizeRedirectOrigin($value) === null) {
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
        $validated = $request->validate(['domain' => ['bail', 'required', 'string', 'max:255']]);
        $origin = self::normalizeRedirectOrigin($validated['domain']) ?? $validated['domain'];
        $user = $request->user();

        $stored = $user->mcp_redirect_domains ?? [];
        $removed = in_array($origin, $stored, true);

        $user->mcp_redirect_domains = array_values(array_filter(
            $stored,
            fn (mixed $domain): bool => ! is_string($domain) || $domain !== $origin,
        ));
        $user->save();

        return redirect()
            ->route('console.mcp.connect')
            ->with('status', $removed
                ? "Redirect origin {$origin} removed."
                : "Redirect origin {$origin} wasn't in the list.");
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

        try {
            $parts = parse_url($input);
        } catch (\ValueError) {
            return null;
        }

        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        if (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        // A leftover bracket means the host was malformed (parse_url
        // strips the brackets of a well-formed IPv6 literal).
        if (str_contains($host, '[') || str_contains($host, ']')) {
            return null;
        }

        // Restore IPv6 brackets or the stored origin is malformed and can
        // never match a redirect URI.
        $host = str_contains($host, ':') ? '['.$host.']' : $host;

        return $scheme.'://'.strtolower($host).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Create or roll a named fleet key: same-name live keys are revoked
     * first, so reusing a name IS the roll. The plaintext is flashed once.
     */
    public function store(Request $request, PluginRepo $repos): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ]);
        $name = $validated['name'] ?? null;
        $name = $name !== null && $name !== '' ? $name : 'default';

        $user = $request->user();

        // Rolling 'default' also retires the legacy single-column key —
        // otherwise isMcpKey would keep accepting it via mcp_key_hash.
        $user->mcpFleetKeys()->where('name', $name)->whereNull('revoked_at')->get()
            ->each(fn (McpFleetKey $key) => $key->revoke());
        if ($name === 'default') {
            $user->mcp_key_hash = null;
            $user->save();
        }

        $plaintext = McpFleetKey::issue($user, $name);

        // Cleanup is a side effect — a filesystem failure must not eat the
        // shown-once plaintext (same contract as agent key rotation).
        try {
            $repos->dropAllRepos();
        } catch (\Throwable $e) {
            Log::warning('plugin repo cleanup failed', ['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('console.dashboard')
            ->with('plaintext_mcp_key', $plaintext)
            ->with('plaintext_mcp_key_name', $name);
    }

    /**
     * Revoke one named fleet key without touching the others.
     */
    public function destroy(Request $request, McpFleetKey $fleetKey, PluginRepo $repos): RedirectResponse
    {
        abort_if($fleetKey->user_id !== $request->user()->id, 404);

        $fleetKey->revoke();

        try {
            $repos->dropAllRepos();
        } catch (\Throwable $e) {
            Log::warning('plugin repo cleanup failed', ['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('console.dashboard')
            ->with('status', "Fleet key '{$fleetKey->name}' revoked.");
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
