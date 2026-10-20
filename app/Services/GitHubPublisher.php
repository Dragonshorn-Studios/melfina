<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Publishes the credential-free fleet package to a GitHub repository the
 * owner picks, so GitHub-only plugin importers (MiniMax's submit accepts
 * github.com URLs, not arbitrary git hosts) can install it.
 *
 * The whole package lands in one commit. Writing each file through the
 * Contents API made a separate commit per path and ran long enough to die
 * (HTTP timeout or the PHP execution limit) after plugin.json was already
 * updated — the manifest then declared skills the commit never contained.
 * A connection failure is not a RuntimeException, so that death was an
 * HTTP 500 rather than a form error.
 */
class GitHubPublisher
{
    /**
     * Push the package to `owner/repo` on the default branch as one
     * commit, creating the repo (public — importers won't carry
     * credentials) under the token's account when it doesn't exist yet.
     *
     * @param  array<string, string>  $files  repo-relative path → contents
     *
     * @throws RuntimeException when the token/repo can't be used
     */
    public function publish(string $token, string $repo, array $files): string
    {
        // The owner clicked one button. A slow GitHub response must come
        // back as a caught error, not a fatal that aborts mid-publish.
        set_time_limit(120);

        try {
            return $this->push($token, $repo, $files);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('GitHub publish failed: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param  array<string, string>  $files
     */
    private function push(string $token, string $repo, array $files): string
    {
        $http = Http::withToken($token)
            ->acceptJson()
            ->timeout(60)
            ->baseUrl('https://api.github.com');

        $login = $this->authenticatedLogin($http);
        [$owner, $name] = $this->splitRepo($repo, $login);
        $full = "{$owner}/{$name}";

        $existing = $http->get("/repos/{$full}");
        if ($existing->notFound()) {
            $created = $this->createRepo($http, $owner, $name, $login);
            $branch = $created['default_branch'] ?? 'main';
        } elseif ($existing->failed()) {
            throw new RuntimeException('Could not read the repo: '.$this->githubMessage($existing));
        } else {
            $branch = $existing->json('default_branch');
        }

        if (! is_string($branch) || $branch === '') {
            throw new RuntimeException('Could not resolve the default branch.');
        }

        $tip = $http->get("/repos/{$full}/branches/{$branch}");
        if ($tip->notFound()) {
            // Repo exists but has no commits — GitHub returns the default
            // branch name and 404s the branch itself. The Contents API used
            // to create that first commit; a root tree does the same.
            $this->commitTree($http, $full, $branch, null, null, $files);

            return "https://github.com/{$full}";
        }

        if ($tip->failed()) {
            throw new RuntimeException('Could not read the default branch: '.$this->githubMessage($tip));
        }

        $parent = $tip->json('commit.sha');
        $baseTree = $tip->json('commit.commit.tree.sha');
        if (! is_string($parent) || $parent === '' || ! is_string($baseTree) || $baseTree === '') {
            throw new RuntimeException('Could not resolve the branch tip.');
        }

        $this->commitTree($http, $full, $branch, $parent, $baseTree, $files);

        return "https://github.com/{$full}";
    }

    /**
     * One tree, one commit, one ref update. `base_tree` keeps files this
     * package does not own (a custom skill, notes). Managed paths that
     * the new package no longer ships are deleted in the same commit, so
     * the manifest and the skill files cannot drift apart.
     *
     * A null parent is the first commit on an empty repo: no base tree,
     * no deletions, and the branch ref is created rather than updated.
     *
     * @param  array<string, string>  $files
     */
    private function commitTree(PendingRequest $http, string $full, string $branch, ?string $parent, ?string $baseTree, array $files): void
    {
        $entries = [];

        foreach ($files as $path => $contents) {
            $entries[] = [
                'path' => $path,
                'mode' => '100644',
                'type' => 'blob',
                'content' => $contents,
            ];
        }

        if ($baseTree !== null) {
            $stale = [];
            foreach ($this->treeBlobs($http, $full, $branch, $baseTree) as $node) {
                $path = $node['path'] ?? '';

                if (($node['type'] ?? '') !== 'blob' || ! is_string($path) || $path === '') {
                    continue;
                }

                if (! isset($files[$path]) && self::managed($path)) {
                    $stale[] = $path;
                }
            }

            sort($stale);

            foreach ($stale as $path) {
                $entries[] = [
                    'path' => $path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha' => null,
                ];
            }
        }

        $payload = ['tree' => $entries];
        if ($baseTree !== null) {
            $payload['base_tree'] = $baseTree;
        }

        $tree = $http->post("/repos/{$full}/git/trees", $payload);

        if ($tree->failed()) {
            throw new RuntimeException('Failed writing the plugin tree: '.$this->githubMessage($tree));
        }

        $treeSha = $tree->json('sha');
        if (! is_string($treeSha) || $treeSha === '') {
            throw new RuntimeException('GitHub did not return a tree sha.');
        }

        // Identical bytes: the tree object is unchanged, so there is
        // nothing to commit. A second publish stays a no-op.
        if ($treeSha === $baseTree) {
            return;
        }

        $commit = $http->post("/repos/{$full}/git/commits", [
            'message' => 'Update Melfina plugin package',
            'tree' => $treeSha,
            'parents' => $parent === null ? [] : [$parent],
        ]);

        if ($commit->failed()) {
            throw new RuntimeException('Failed creating the plugin commit: '.$this->githubMessage($commit));
        }

        $commitSha = $commit->json('sha');
        if (! is_string($commitSha) || $commitSha === '') {
            throw new RuntimeException('GitHub did not return a commit sha.');
        }

        $ref = $parent === null
            ? $http->post("/repos/{$full}/git/refs", [
                'ref' => 'refs/heads/'.$branch,
                'sha' => $commitSha,
            ])
            : $http->patch("/repos/{$full}/git/refs/heads/{$branch}", [
                'sha' => $commitSha,
            ]);

        if ($ref->failed()) {
            throw new RuntimeException('Failed updating the branch: '.$this->githubMessage($ref));
        }
    }

    /**
     * Every blob in the current tree. A truncated recursive listing falls
     * back to the managed subtrees — those are the only paths publish is
     * allowed to delete.
     *
     * @return list<array<string, mixed>>
     */
    private function treeBlobs(PendingRequest $http, string $full, string $branch, string $baseTree): array
    {
        $tree = $http->get("/repos/{$full}/git/trees/{$baseTree}", ['recursive' => '1']);

        if ($tree->failed()) {
            throw new RuntimeException('Could not read the repo tree: '.$this->githubMessage($tree));
        }

        if ($tree->json('truncated')) {
            return $this->managedTreeNodes($http, $full, $branch);
        }

        return $tree->json('tree') ?? [];
    }

    /**
     * Managed subtrees fetched one at a time when the whole-repo
     * recursive tree comes back truncated — the package's footprint is
     * small enough that each of these fits under the limit.
     *
     * @return list<array<string, mixed>>
     */
    private function managedTreeNodes(PendingRequest $http, string $full, string $branch): array
    {
        $nodes = [];

        foreach (['', 'skills', 'scripts', '.minimax-plugin', '.claude-plugin'] as $dir) {
            $tree = $http->get("/repos/{$full}/git/trees/{$branch}".($dir === '' ? '' : ":{$dir}"), ['recursive' => $dir === '' ? '0' : '1']);

            if ($tree->status() === 404) {
                continue;
            }

            if ($tree->failed() || $tree->json('truncated')) {
                throw new RuntimeException("Could not list {$dir} in the repo — stale files may remain; delete them manually.");
            }

            foreach ($tree->json('tree', []) as $node) {
                // Shallow dir listings return paths relative to the dir —
                // re-root them so `managed` sees repo-relative paths.
                if ($dir !== '' && isset($node['path'])) {
                    $node['path'] = $dir.'/'.$node['path'];
                }
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Whether a repo path belongs to the generated package surface —
     * the only files publish is allowed to delete. Deliberately narrow:
     * melfina-prefixed skills and Melfina's own file names, never a
     * generic skills/ or scripts/ tree the owner may share.
     */
    private static function managed(string $path): bool
    {
        return str_starts_with($path, 'skills/melfina')
            || in_array($path, [
                'scripts/melfina-call.mjs',
                'melfina-api.json',
                '.minimax-plugin/plugin.json',
                '.claude-plugin/plugin.json',
            ], true);
    }

    /**
     * The login the token authenticates as — used to default bare repo
     * names and to refuse creating repos under other owners.
     */
    private function authenticatedLogin(PendingRequest $http): string
    {
        try {
            $response = $http->get('/user');
        } catch (RequestException $e) {
            throw new RuntimeException('GitHub unreachable: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            throw new RuntimeException('GitHub rejected the token (401) — check it has the repo scope.');
        }

        $login = $response->json('login');
        if (! is_string($login) || $login === '') {
            throw new RuntimeException('Could not resolve the token\'s GitHub login.');
        }

        return $login;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitRepo(string $repo, string $login): array
    {
        $repo = trim($repo, " \t/");

        if (! preg_match('#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)?$#', $repo)) {
            throw new RuntimeException('Repo must be "name" or "owner/name".');
        }

        if (! str_contains($repo, '/')) {
            return [$login, $repo];
        }

        return explode('/', $repo, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function createRepo(PendingRequest $http, string $owner, string $name, string $login): array
    {
        if ($owner !== $login) {
            throw new RuntimeException("{$owner}/{$name} doesn't exist and the token belongs to {$login} — create the repo on GitHub first, or use a repo under {$login}.");
        }

        $response = $http->post('/user/repos', [
            'name' => $name,
            'private' => false,
            'auto_init' => true,
            'description' => 'Melfina fleet plugin package',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Could not create the repo: '.$this->githubMessage($response));
        }

        $created = $response->json();

        return is_array($created) ? $created : [];
    }

    /**
     * GitHub's `message` is usually a string. Some error bodies make it
     * an array; concatenating that is a TypeError and used to surface as
     * a bare 500.
     */
    private function githubMessage(Response $response): string
    {
        $message = $response->json('message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return 'GitHub error (HTTP '.$response->status().')';
    }
}
