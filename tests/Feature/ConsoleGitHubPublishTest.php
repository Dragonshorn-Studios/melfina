<?php

use App\Mcp\PluginRepo;
use App\Models\User;
use Illuminate\Support\Facades\Http;

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
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'octo']),
        'api.github.com/repos/octo/melfina-plugin' => Http::response(['id' => 1, 'default_branch' => 'main']),
        'api.github.com/repos/octo/melfina-plugin/contents/*' => Http::response(['content' => []], 200),
        'api.github.com/repos/octo/melfina-plugin/git/trees/*' => Http::response(['tree' => []]),
    ]);

    $owner = User::factory()->owner()->create([
        'github_token' => 'ghp_ok',
        'github_repo' => 'octo/melfina-plugin',
    ]);

    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'))
        ->assertRedirect(route('console.mcp.connect'))
        ->assertSessionHas('status', 'Published to https://github.com/octo/melfina-plugin');

    // Every package file gets a contents PUT.
    $fileCount = count(app(PluginRepo::class)->publicPackageFiles());
    Http::assertSentCount(4 + $fileCount * 2); // /user + repo GET ×2 (publish + stale sweep) + per-file GET sha + PUT + tree GET
});

it('deletes files a previous layout publish left behind', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'octo']),
        'api.github.com/repos/octo/melfina-plugin' => Http::response(['id' => 1, 'default_branch' => 'main']),
        'api.github.com/repos/octo/melfina-plugin/contents/*' => Http::response(['content' => []], 200),
        'api.github.com/repos/octo/melfina-plugin/git/trees/*' => Http::response(['tree' => [
            ['path' => 'skills/melfina-read-file/SKILL.md', 'type' => 'blob', 'sha' => 'stalesha'],
            ['path' => 'docs/human-notes.md', 'type' => 'blob', 'sha' => 'ownsha'],
        ]]),
    ]);

    $owner = User::factory()->owner()->create([
        'github_token' => 'ghp_ok',
        'github_repo' => 'octo/melfina-plugin',
    ]);

    // Publishing the single layout must sweep the per-tool skill the
    // previous multi-layout publish wrote, but never touch content the
    // package doesn't own.
    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'), ['variant' => 'single'])
        ->assertSessionHas('status', 'Published to https://github.com/octo/melfina-plugin');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/octo/melfina-plugin/contents/skills/melfina-read-file/SKILL.md'
        && $request['sha'] === 'stalesha');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'docs/human-notes.md'));
});

it('creates the repo under the token login when it does not exist', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'octo']),
        'api.github.com/repos/octo/newrepo' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/user/repos' => Http::response(['full_name' => 'octo/newrepo'], 201),
        'api.github.com/repos/octo/newrepo/contents/*' => Http::response(['content' => []], 200),
    ]);

    $owner = User::factory()->owner()->create([
        'github_token' => 'ghp_ok',
        'github_repo' => 'newrepo',
    ]);

    $this->actingAs($owner)
        ->post(route('console.mcp.github.publish'))
        ->assertSessionHas('status', 'Published to https://github.com/octo/newrepo');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/user/repos'
        && $request['private'] === false);
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
