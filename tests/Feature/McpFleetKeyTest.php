<?php

use App\Mcp\PluginRepo;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function () {
    File::deleteDirectory(storage_path('app/mcp-plugins'));

    $this->owner = User::factory()->owner()->create();
    $this->fleetKey = $this->owner->rotateMcpKey();
    $this->agent = Agent::factory()->withKey()->create();

    $this->mcp = function (string $method, array $params = [], ?string $bearer = 'melf_testkey', array $headers = []) {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], array_merge(['Accept' => 'application/json'], $headers, $bearer === null ? [] : ['Authorization' => 'Bearer '.$bearer]));
    };
});

it('rejects a fleet bearer without an agent key', function () {
    ($this->mcp)('tools/list', [], $this->fleetKey)
        ->assertUnauthorized()
        ->assertJson(['error' => 'agent persona required']);
});

it('resolves the persona from the agent name', function () {
    ($this->mcp)('tools/list', [], $this->fleetKey, ['X-Melfina-Agent-Key' => $this->agent->name])
        ->assertOk();

    $this->postJson('/mcp?agent_key='.$this->agent->name, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$this->fleetKey])->assertOk();
});

it('fails closed on an ambiguous or disabled agent name', function () {
    Agent::factory()->withKey('melf_twin')->create(['name' => $this->agent->name]);

    ($this->mcp)('tools/list', [], $this->fleetKey, ['X-Melfina-Agent-Key' => $this->agent->name])
        ->assertUnauthorized();

    $twin = Agent::where('key_hash', Agent::hashKey('melf_twin'))->firstOrFail();
    $twin->update(['status' => Agent::STATUS_DISABLED]);

    ($this->mcp)('tools/list', [], $this->fleetKey, ['X-Melfina-Agent-Key' => $this->agent->name])
        ->assertOk();

    $this->agent->update(['status' => Agent::STATUS_DISABLED]);

    ($this->mcp)('tools/list', [], $this->fleetKey, ['X-Melfina-Agent-Key' => $this->agent->name])
        ->assertUnauthorized();
});

it('resolves the persona from the agent key header', function () {
    ($this->mcp)('tools/list', [], $this->fleetKey, ['X-Melfina-Agent-Key' => 'melf_testkey'])
        ->assertOk();
});

it('resolves the persona from the agent_key query param (names only)', function () {
    $this->postJson('/mcp?agent_key='.$this->agent->name, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$this->fleetKey])->assertOk();

    // Secrets stay out of query strings — the key form is header-only.
    $this->postJson('/mcp?agent_key=melf_testkey', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$this->fleetKey])->assertUnauthorized();
});

it('rejects a fleet bearer with an unknown or disabled agent key', function () {
    ($this->mcp)('tools/list', [], $this->fleetKey, ['X-Melfina-Agent-Key' => 'melf_wrong'])
        ->assertUnauthorized();

    $this->agent->update(['status' => Agent::STATUS_DISABLED]);

    ($this->mcp)('tools/list', [], $this->fleetKey, ['X-Melfina-Agent-Key' => 'melf_testkey'])
        ->assertUnauthorized();
});

it('scopes fleet-mode calls to the presented agent', function () {
    $other = Agent::factory()->withKey('melf_otherkey')->create();

    ($this->mcp)('tools/call', [
        'name' => 'write_file',
        'arguments' => ['path' => 'note.txt', 'content' => 'via fleet'],
    ], $this->fleetKey, ['X-Melfina-Agent-Key' => 'melf_otherkey'])->assertOk();

    expect($other->files()->where('path', 'note.txt')->exists())->toBeTrue()
        ->and($this->agent->files()->where('path', 'note.txt')->exists())->toBeFalse();
});

it('rejects a bogus fleet bearer', function () {
    ($this->mcp)('tools/list', [], 'melfg_wrong', ['X-Melfina-Agent-Key' => 'melf_testkey'])
        ->assertUnauthorized();
});

it('still accepts a direct agent bearer', function () {
    ($this->mcp)('tools/list', [])->assertOk();
});

it('lets the owner generate and rotate the fleet key from the console', function () {
    $old = $this->fleetKey;
    expect(User::isMcpKey($old))->toBeTrue();

    $this->actingAs($this->owner)
        ->post(route('console.mcp-key.store'))
        ->assertRedirect(route('console.dashboard'))
        ->assertSessionHas('plaintext_mcp_key');

    expect(User::isMcpKey($old))->toBeFalse();
});

it('keeps non-owners off the fleet key rotation', function () {
    $this->post(route('console.mcp-key.store'))->assertRedirect(route('login'));

    $plain = User::factory()->create();
    $this->actingAs($plain)->post(route('console.mcp-key.store'))->assertForbidden();
});

it('challenges anonymous plugin repo requests', function () {
    $this->get('/plugins/melfina.git/info/refs')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="melfina-plugins"');
});

it('serves the direct-form plugin repo over the dumb git protocol', function () {
    $clone = fn (string $path) => $this
        ->withBasicAuth('whatever', 'melf_testkey')
        ->get('/plugins/melfina.git/'.$path);

    $clone('info/refs')->assertOk();
    $clone('HEAD')->assertOk();

    // BinaryFileResponse content isn't captured in tests — assert on the
    // built repo files behind the 200s.
    $dir = app(PluginRepo::class)->repoDir($this->agent, 'direct');

    expect(file_get_contents($dir.'/info/refs'))->toContain('refs/heads/main');
    expect(file_get_contents($dir.'/HEAD'))->toContain('refs/heads/main');

    $pack = collect(explode("\n", trim((string) file_get_contents($dir.'/objects/info/packs'))))
        ->map(fn ($line) => trim(str_replace('P ', '', $line)))
        ->filter()
        ->first();

    $clone('objects/pack/'.$pack)->assertOk();
});

it('serves the fleet-form repo keyed by persona name or agent key', function () {
    $clone = fn (string $user) => $this
        ->withBasicAuth($user, $this->fleetKey)
        ->get('/plugins/melfina.git/info/refs');

    $clone($this->agent->name)->assertOk();
    $clone('melf_testkey')->assertOk();

    $repo = app(PluginRepo::class)->repoDir($this->agent, 'fleet');
    $mcp = new Process(['git', '--git-dir='.$repo, 'show', 'HEAD:mcp.json']);
    $mcp->run();

    expect($mcp->getOutput())
        ->toContain('Bearer '.$this->fleetKey)
        ->toContain('X-Melfina-Agent-Key')
        ->toContain($this->agent->name)
        ->not->toContain('melf_testkey');
});

it('keeps the two clone modes in separate repos', function () {
    $this->withBasicAuth('x', 'melf_testkey')->get('/plugins/a.git/info/refs')->assertOk();
    $this->withBasicAuth($this->agent->name, $this->fleetKey)->get('/plugins/a.git/info/refs')->assertOk();

    $repos = app(PluginRepo::class);
    expect(is_dir($repos->repoDir($this->agent, 'direct')))->toBeTrue()
        ->and(is_dir($repos->repoDir($this->agent, 'fleet')))->toBeTrue();
});

it('404s plugin repo requests with dead credentials', function () {
    $this->withBasicAuth('x', 'melf_deadkey')->get('/plugins/a.git/info/refs')->assertNotFound();
    $this->withBasicAuth('x', 'melfg_dead')->get('/plugins/a.git/info/refs')->assertNotFound();
    $this->withBasicAuth('no-such-agent', $this->fleetKey)->get('/plugins/a.git/info/refs')->assertNotFound();
});

it('refuses to serve paths outside the repo', function () {
    $repos = app(PluginRepo::class);
    $dir = $repos->ensureBuilt($this->agent, 'direct', 'melf_testkey');

    // The fingerprint sits beside the repo and shares its name prefix —
    // a bare prefix check would leak it.
    expect($repos->file($dir, '../'.basename($dir).'.fingerprint'))->toBeNull()
        ->and($repos->file($dir, '../../.env'))->toBeNull();

    $this->withBasicAuth('x', 'melf_testkey')
        ->get('/plugins/a.git/../'.basename($dir).'.fingerprint')
        ->assertNotFound();
});

it('lets the owner download the plugin zip with live keys', function () {
    app(PluginRepo::class)->rebuildPackage($this->agent, 'melf_testkey');

    $this->actingAs($this->owner)
        ->get(route('console.agents.plugin', $this->agent))
        ->assertOk()
        ->assertHeader('content-disposition');

    $mcp = file_get_contents(app(PluginRepo::class)->packageDir($this->agent).'/mcp.json');
    expect($mcp)->toContain('Bearer melf_testkey');
});

it('keeps the plugin zip owner-only', function () {
    app(PluginRepo::class)->rebuildPackage($this->agent, 'melf_testkey');

    $this->get(route('console.agents.plugin', $this->agent))->assertRedirect(route('login'));

    $plain = User::factory()->create();
    $this->actingAs($plain)->get(route('console.agents.plugin', $this->agent))->assertForbidden();
});

it('builds the zip package as part of agent create and rotate', function () {
    $repos = app(PluginRepo::class);

    $this->actingAs($this->owner)
        ->post(route('console.agents.store'), ['name' => 'packaged'])
        ->assertSessionHas('plaintext_key');

    $newAgent = Agent::where('name', 'packaged')->firstOrFail();
    $mcp = file_get_contents($repos->packageDir($newAgent).'/mcp.json');
    expect($mcp)->toContain('Bearer melf_')->not->toContain('melf_testkey');

    $this->actingAs($this->owner)
        ->post(route('console.agents.rotate', $this->agent))
        ->assertSessionHas('plaintext_key');

    $mcp = file_get_contents($repos->packageDir($this->agent).'/mcp.json');
    expect($mcp)->toContain('Bearer '.session('plaintext_key'))
        ->not->toContain('melf_testkey');
});

it('drops the stale package and repos when the agent is renamed', function () {
    $repos = app(PluginRepo::class);
    $this->withBasicAuth('x', 'melf_testkey')->get('/plugins/a.git/info/refs')->assertOk();
    $repos->rebuildPackage($this->agent, 'melf_testkey');

    $this->actingAs($this->owner)
        ->put(route('console.agents.update', $this->agent), [
            'name' => 'renamed-agent',
            'status' => Agent::STATUS_ACTIVE,
        ]);

    expect(is_dir($repos->repoDir($this->agent, 'direct')))->toBeFalse()
        ->and(is_dir($repos->packageDir($this->agent)))->toBeFalse();
});

it('claims an agent named like a key by name', function () {
    $melfNamed = Agent::factory()->withKey('melf_namedx')->create(['name' => 'melf_helper']);

    ($this->mcp)('tools/list', [], $this->fleetKey, ['X-Melfina-Agent-Key' => 'melf_helper'])
        ->assertOk();
});

it('does not treat an agent key as a fleet key', function () {
    expect(User::isMcpKey('melf_testkey'))->toBeFalse();
});

it('404s the plugin zip before a package exists', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.plugin', $this->agent))
        ->assertNotFound();
});

it('drops plugin repos embedding a rotated key and rebuilds on demand', function () {
    $repos = app(PluginRepo::class);

    $this->withBasicAuth($this->agent->name, $this->fleetKey)
        ->get('/plugins/a.git/info/refs')->assertOk();
    expect(is_dir($repos->repoDir($this->agent, 'fleet')))->toBeTrue();

    $this->actingAs($this->owner)->post(route('console.mcp-key.store'));
    expect(is_dir($repos->repoDir($this->agent, 'fleet')))->toBeFalse();

    $this->withBasicAuth('x', 'melf_testkey')->get('/plugins/a.git/info/refs')->assertOk();
    expect(is_dir($repos->repoDir($this->agent, 'direct')))->toBeTrue();

    $this->actingAs($this->owner)
        ->post(route('console.agents.rotate', $this->agent));
    expect(is_dir($repos->repoDir($this->agent, 'direct')))->toBeFalse();

    // A fresh clone under the new key rebuilds and serves again.
    $newKey = session('plaintext_key');
    $this->withBasicAuth('x', $newKey)->get('/plugins/a.git/info/refs')->assertOk();
    expect(is_dir($repos->repoDir($this->agent, 'direct')))->toBeTrue();
});
