<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Publishes the credential-free fleet package to a GitHub repository the
 * owner picks, so GitHub-only plugin importers (MiniMax's submit accepts
 * github.com URLs, not arbitrary git hosts) can install it.
 */
class GitHubPublisher
{
    /**
     * Push each package file to `owner/repo` on the default branch,
     * creating the repo (public — importers won't carry credentials) under
     * the token's account when it doesn't exist yet.
     *
     * @param  array<string, string>  $files  repo-relative path → contents
     *
     * @throws RuntimeException when the token/repo can't be used
     */
    public function publish(string $token, string $repo, array $files): string
    {
        $http = Http::withToken($token)
            ->acceptJson()
            ->baseUrl('https://api.github.com');

        $login = $this->authenticatedLogin($http);
        [$owner, $name] = $this->splitRepo($repo, $login);
        $full = "{$owner}/{$name}";

        $existing = $http->get("/repos/{$full}");
        if ($existing->notFound()) {
            $this->createRepo($http, $owner, $name, $login);
        } else {
            $existing->throw();
        }

        foreach ($files as $path => $contents) {
            $this->putFile($http, $full, $path, $contents);
        }

        return "https://github.com/{$full}";
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

    private function createRepo(PendingRequest $http, string $owner, string $name, string $login): void
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
            throw new RuntimeException('Could not create the repo: '.($response->json('message') ?? 'GitHub error'));
        }
    }

    /**
     * Upsert one file through the contents API (fetch the sha when it
     * exists, then PUT the update).
     */
    private function putFile(PendingRequest $http, string $full, string $path, string $contents): void
    {
        $sha = $http->get("/repos/{$full}/contents/{$path}")->json('sha');

        $response = $http->put("/repos/{$full}/contents/{$path}", array_filter([
            'message' => "Update {$path}",
            'content' => base64_encode($contents),
            'sha' => is_string($sha) ? $sha : null,
        ]));

        if ($response->failed()) {
            throw new RuntimeException("Failed writing {$path}: ".($response->json('message') ?? 'GitHub error'));
        }
    }
}
