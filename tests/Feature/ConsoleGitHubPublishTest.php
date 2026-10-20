<?php

use App\Mcp\PluginRepo;
use App\Mcp\ToolCatalog;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

it('saves the repo and stores the token encrypted', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('console.mcp.github.store'), [
            'github_token' => 'ghp_testtoken123',
            'github_repo' => 'me/melfina-plugin',
        ])
        ->assertRedirect(route('console.mcp.connect'));

    $owner->refresh();

    // Column holds ciphertext (Laravel 'encrypted' cast), never plaintext.
    expect($owner->getRawOriginal('github_token'))->not->toBe('ghp_testtoken123')
        ->and($owner->github_token)->toBe('ghp_testtoken123')
        ->and($owner->github_repo)->toBe('me/melfina-plugin');
});

it('keeps the saved token when the form only updates the repo', function () {
    $owner = User::factory()->owner()->create(['github_token' => 'ghp_keep', 'github_repo' => 'me/old']);

    $this->actingAs($owner)
        ->post(route('console.mcp.github.store'), [
            'github_token' => '',
            'github_repo' => 'me/new',
        ])
        ->assertRedirect(route('console.mcp.connect'));

    expect($owner->fresh()->github_token)->toBe('ghp_keep')
        ->and($owner->fresh()->github_repo)->toBe('me/new');
});

it('rejects a malformed repo name', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('console.mcp.github.store'), [
            'github_token' => 'x',
            'github_repo' => 'not a repo!!',
        ])
        ->assertSessionHasErrors('github_repo');
});

it('refuses to publish without saved credentials', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'))
        ->assertSessionHasErrors('github_repo');
});

it('pushes every package file to the chosen repo', function () {
    $capture = githubPublishCapture();

    $owner = User::factory()->owner()->create([
        'github_token' => 'ghp_ok',
        'github_repo' => 'octo/melfina-plugin',
    ]);

    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'))
        ->assertRedirect(route('console.mcp.connect'))
        ->assertSessionHas('status', 'Published to https://github.com/octo/melfina-plugin');

    $committed = collect($capture->tree['tree'] ?? [])
        ->filter(fn (array $entry): bool => array_key_exists('content', $entry))
        ->mapWithKeys(fn (array $entry): array => [$entry['path'] => $entry['content']]);

    expect($committed->all())->toEqual(app(PluginRepo::class)->publicPackageFiles());
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/git/commits'));
});

it('deletes files a previous layout publish left behind', function () {
    $capture = githubPublishCapture([
        ['path' => 'skills/melfina-read-file/SKILL.md', 'type' => 'blob', 'sha' => 'stalesha'],
        ['path' => 'skills/custom-helper/SKILL.md', 'type' => 'blob', 'sha' => 'ownskill'],
        ['path' => 'docs/human-notes.md', 'type' => 'blob', 'sha' => 'ownsha'],
    ]);

    $owner = User::factory()->owner()->create([
        'github_token' => 'ghp_ok',
        'github_repo' => 'octo/melfina-plugin',
    ]);

    // Publishing the single layout must sweep the per-tool skill the
    // previous multi-layout publish wrote, but never touch content the
    // package doesn't own. base_tree keeps those; only a null sha deletes.
    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'), ['variant' => 'single'])
        ->assertSessionHas('status', 'Published to https://github.com/octo/melfina-plugin');

    $entries = collect($capture->tree['tree'] ?? [])->keyBy('path');
    $removed = $entries['skills/melfina-read-file/SKILL.md'];

    expect($removed['sha'])->toBeNull()
        ->and($removed)->not->toHaveKey('content')
        ->and($entries)->not->toHaveKey('skills/custom-helper/SKILL.md')
        ->and($entries)->not->toHaveKey('docs/human-notes.md');
});

it('creates the repo under the token login when it does not exist', function () {
    Http::fake(function (Request $request) {
        $method = $request->method();
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

        if ($method === 'GET' && $path === '/user') {
            return Http::response(['login' => 'octo']);
        }

        if ($method === 'GET' && $path === '/repos/octo/newrepo') {
            return Http::response(['message' => 'Not Found'], 404);
        }

        if ($method === 'POST' && $path === '/user/repos') {
            return Http::response(['full_name' => 'octo/newrepo', 'default_branch' => 'main'], 201);
        }

        if ($method === 'GET' && str_contains($path, '/branches/')) {
            return Http::response([
                'commit' => [
                    'sha' => 'parentsha',
                    'commit' => ['tree' => ['sha' => 'basesha']],
                ],
            ]);
        }

        if ($method === 'GET' && str_contains($path, '/git/trees/')) {
            return Http::response(['truncated' => false, 'tree' => []]);
        }

        if ($method === 'POST' && str_contains($path, '/git/trees')) {
            return Http::response(['sha' => 'newtree']);
        }

        if ($method === 'POST' && str_contains($path, '/git/commits')) {
            return Http::response(['sha' => 'newcommit']);
        }

        if ($method === 'PATCH' && str_contains($path, '/git/refs/')) {
            return Http::response(['ref' => 'refs/heads/main']);
        }

        return Http::response(['message' => 'unexpected '.$method.' '.$path], 500);
    });

    $owner = User::factory()->owner()->create([
        'github_token' => 'ghp_ok',
        'github_repo' => 'newrepo',
    ]);

    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'))
        ->assertSessionHas('status', 'Published to https://github.com/octo/newrepo');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/user/repos'
        && $request['private'] === false);
});

it('publishes the first commit into an existing empty repo', function () {
    $captured = (object) ['tree' => null, 'commit' => null, 'ref' => null];

    Http::fake(function (Request $request) use ($captured) {
        $method = $request->method();
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

        if ($method === 'GET' && $path === '/user') {
            return Http::response(['login' => 'octo']);
        }

        if ($method === 'GET' && $path === '/repos/octo/empty') {
            return Http::response(['default_branch' => 'main']);
        }

        if ($method === 'GET' && str_contains($path, '/branches/')) {
            return Http::response(['message' => 'Branch not found'], 404);
        }

        if ($method === 'POST' && str_contains($path, '/git/trees')) {
            $captured->tree = $request->data();

            return Http::response(['sha' => 'newtree']);
        }

        if ($method === 'POST' && str_contains($path, '/git/commits')) {
            $captured->commit = $request->data();

            return Http::response(['sha' => 'newcommit']);
        }

        if ($method === 'POST' && $path === '/repos/octo/empty/git/refs') {
            $captured->ref = $request->data();

            return Http::response(['ref' => 'refs/heads/main']);
        }

        return Http::response(['message' => 'unexpected '.$method.' '.$path], 500);
    });

    $owner = User::factory()->owner()->create([
        'github_token' => 'ghp_ok',
        'github_repo' => 'octo/empty',
    ]);

    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'))
        ->assertSessionHas('status', 'Published to https://github.com/octo/empty');

    $paths = collect($captured->tree['tree'] ?? [])->pluck('path');

    expect($captured->tree)->not->toHaveKey('base_tree')
        ->and($captured->commit['parents'] ?? null)->toBe([])
        ->and($captured->ref)->toMatchArray(['ref' => 'refs/heads/main', 'sha' => 'newcommit'])
        ->and($paths)->toContain('skills/melfina-knowledge-connect/SKILL.md')
        ->and($paths)->toContain('.minimax-plugin/plugin.json');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
});

it('commits a skill file for every live tool into one tree', function () {
    $capture = githubPublishCapture();

    $owner = User::factory()->owner()->create([
        'github_token' => 'ghp_ok',
        'github_repo' => 'octo/melfina-plugin',
    ]);

    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'))
        ->assertRedirect(route('console.mcp.connect'))
        ->assertSessionHas('status');

    $entries = collect($capture->tree['tree'] ?? [])->keyBy('path');
    $tools = app(ToolCatalog::class)->all();

    // The committed tree — not the manifest alone — carries one skill per
    // live tool. These three are last in catalog order; a contents-API
    // loop that dies after plugin.json is written leaves them declared
    // and absent.
    foreach ($tools as $tool) {
        $path = 'skills/melfina-'.str_replace('_', '-', $tool['name']).'/SKILL.md';
        expect($entries)->toHaveKey($path)
            ->and($entries[$path]['content'] ?? '')->toContain($tool['description']);
    }

    $manifest = json_decode($entries['melfina-api.json']['content'] ?? '', true);
    $plugin = json_decode($entries['.minimax-plugin/plugin.json']['content'] ?? '', true);
    $declared = $plugin['skills'];
    $skillPaths = $entries->keys()->filter(fn (string $path): bool => str_starts_with($path, 'skills/'))->values()->all();

    expect($declared)->toHaveCount(count($tools) + 1)
        ->and($declared)->toEqualCanonicalizing($skillPaths)
        ->and(collect($manifest['tools'])->pluck('name')->all())->toEqualCanonicalizing(collect($tools)->pluck('name')->all())
        ->and($entries['mcp.json']['content'])->toContain('Bearer YOUR_KEY')
        ->and($entries['mcp.json']['content'])->not->toMatch('/melf[gs]?_[A-Za-z0-9]/');

    // One commit, not one contents PUT per file. A second publish of the
    // same bytes sends the same tree.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/contents/'));
    expect($capture->trees)->toHaveCount(1);

    $this->actingAs($owner)->post(route('console.mcp.github.publish'));
    expect($capture->trees[1])->toEqual($capture->trees[0]);

    // The local dumb-HTTP repo agrees, and a second build is the same commit.
    $repos = app(PluginRepo::class);
    $dir = $repos->ensureGlobalPublic();
    $sha = trim((new Process(['git', '--git-dir='.$dir, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    $listed = trim((new Process(['git', '--git-dir='.$dir, 'ls-tree', '-r', '--name-only', 'HEAD']))->mustRun()->getOutput());
    foreach ($tools as $tool) {
        $path = 'skills/melfina-'.str_replace('_', '-', $tool['name']).'/SKILL.md';
        expect($listed)->toContain($path);
    }
    $repos->ensureGlobalPublic();
    $again = trim((new Process(['git', '--git-dir='.$dir, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    expect($again)->toBe($sha);
});

it('surfaces a GitHub timeout as a form error', function () {
    Http::fake(function (): never {
        throw new ConnectionException('Operation timed out');
    });

    $owner = User::factory()->owner()->create([
        'github_token' => 'ghp_ok',
        'github_repo' => 'octo/melfina-plugin',
    ]);

    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'))
        ->assertSessionHasErrors('github_repo');
});

it('surfaces a GitHub auth failure as a form error', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $owner = User::factory()->owner()->create([
        'github_token' => 'ghp_bad',
        'github_repo' => 'octo/melfina-plugin',
    ]);

    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'))
        ->assertSessionHasErrors('github_repo');
});

/**
 * Stub the GitHub REST surface the publisher talks to, and keep every
 * tree payload it posts. Contents-API calls are answered so the pre-fix
 * per-file loop can finish — it just never fills `tree`.
 *
 * @param  list<array<string, mixed>>  $blobs
 * @return object{tree: array<string, mixed>|null, trees: list<array<string, mixed>>}
 */
function githubPublishCapture(array $blobs = []): object
{
    $capture = (object) ['tree' => null, 'trees' => []];

    Http::fake(function (Request $request) use ($capture, $blobs) {
        $method = $request->method();
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

        if ($method === 'GET' && $path === '/user') {
            return Http::response(['login' => 'octo']);
        }

        if ($method === 'GET' && preg_match('#/repos/[^/]+/[^/]+/branches/#', $path)) {
            return Http::response([
                'commit' => [
                    'sha' => 'parentsha',
                    'commit' => ['tree' => ['sha' => 'basesha']],
                ],
            ]);
        }

        if ($method === 'GET' && str_contains($path, '/git/trees/')) {
            return Http::response(['truncated' => false, 'tree' => $blobs]);
        }

        if ($method === 'POST' && str_contains($path, '/git/trees')) {
            $capture->tree = $request->data();
            $capture->trees[] = $request->data();

            return Http::response(['sha' => 'newtree']);
        }

        if ($method === 'POST' && str_contains($path, '/git/commits')) {
            return Http::response(['sha' => 'newcommit']);
        }

        if ($method === 'PATCH' && str_contains($path, '/git/refs/')) {
            return Http::response(['ref' => 'refs/heads/main']);
        }

        if ($method === 'GET' && preg_match('#^/repos/[^/]+/[^/]+$#', $path)) {
            return Http::response(['default_branch' => 'main']);
        }

        if (str_contains($path, '/contents/')) {
            return Http::response(['sha' => 'filesha']);
        }

        return Http::response(['message' => 'unexpected '.$method.' '.$path], 500);
    });

    return $capture;
}
