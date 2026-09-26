<?php

use App\Mcp\PluginRepo;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

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

it('fails closed on a disabled agent name', function () {
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

it('claims the persona from a Basic username on /mcp', function () {
    // URL-only clients fold both credentials into the endpoint:
    // https://{name}:{fleet}@host/mcp — username is the persona.
    $this->withBasicAuth($this->agent->name, $this->fleetKey)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertOk();

    // Persona as the agent's key works the same way.
    $this->withBasicAuth('melf_testkey', $this->fleetKey)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertOk();

    // The Basic username wins over the persona header when both are sent.
    $other = Agent::factory()->withKey('melf_otherkey')->create(['name' => 'otherbot']);
    $this->withBasicAuth('otherbot', $this->fleetKey)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'write_file', 'arguments' => ['path' => 'who.txt', 'content' => 'basic']],
        ], ['X-Melfina-Agent-Key' => $this->agent->name])
        ->assertOk();
    expect($other->files()->where('path', 'who.txt')->exists())->toBeTrue();

    $this->withBasicAuth('no-such-agent', $this->fleetKey)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertUnauthorized();

    // A disabled agent's name fails closed through the same path.
    $this->agent->update(['status' => Agent::STATUS_DISABLED]);
    $this->withBasicAuth($this->agent->name, $this->fleetKey)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertUnauthorized();
    $this->agent->update(['status' => Agent::STATUS_ACTIVE]);
});

it('treats an empty Basic username like a bare fleet Bearer', function () {
    // https://:{fleet}@host/mcp + ?agent_key=name ≡ Bearer fleet + param.
    $this->withBasicAuth('', $this->fleetKey)
        ->postJson('/mcp?agent_key='.$this->agent->name, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertOk();

    // No persona anywhere still fails closed.
    $this->withBasicAuth('', $this->fleetKey)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertUnauthorized();
});

it('lets Basic credentials preempt a Bearer agent key', function () {
    $other = Agent::factory()->withKey('melf_otherkey')->create(['name' => 'otherbot']);

    // One Authorization header can't hold two schemes — the dual-credential
    // case is PHP_AUTH_* server vars (proxy-forwarded) plus a Bearer header.
    $this->withServerVariables([
        'PHP_AUTH_USER' => 'otherbot',
        'PHP_AUTH_PW' => $this->fleetKey,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'write_file', 'arguments' => ['path' => 'precedence.txt', 'content' => 'x']],
    ], ['Authorization' => 'Bearer melf_testkey'])
        ->assertOk();

    expect($other->files()->where('path', 'precedence.txt')->exists())->toBeTrue()
        ->and($this->agent->files()->where('path', 'precedence.txt')->exists())->toBeFalse();
});

it('accepts a dedicated agent key as the Basic password', function () {
    // URL-only client holding just an agent key: https://x:melf_…@host/mcp
    // — the username is ignored, mirroring the plugin clone surface.
    $this->withBasicAuth('whatever', 'melf_testkey')
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertOk();

    $this->withBasicAuth('x', 'melf_deadkey')
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertUnauthorized();
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
        ->get('/plugins/~melfina.git/'.$path);

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
        ->get('/plugins/a.git/info/refs');

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

it('serves the fleet-wide ~melfina.git package without a persona', function () {
    $clone = fn (string $path) => $this
        ->withBasicAuth('melfina', $this->fleetKey)
        ->get('/plugins/~melfina.git/'.$path);

    $clone('info/refs')->assertOk();
    $clone('HEAD')->assertOk();

    $dir = app(PluginRepo::class)->globalDir();
    $show = fn (string $file) => tap(new Process(['git', '--git-dir='.$dir, 'show', 'HEAD:'.$file]), fn ($p) => $p->run())->getOutput();

    $mcp = $show('mcp.json');
    expect($mcp)
        ->toContain('YOUR_AGENT_NAME:'.$this->fleetKey.'@')
        ->toContain('/mcp')
        ->not->toContain('X-Melfina-Agent-Key')
        ->not->toContain('melf_testkey');

    expect(json_decode($show('plugin.json'), true)['name'])->toBe('melfina')
        ->and(json_decode($show('.minimax-plugin/plugin.json'), true)['name'])->toBe('melfina')
        ->and($show('skills/melfina/SKILL.md'))->toContain('YOUR_AGENT_NAME');

    // An agent key presented as the password still gets the per-agent
    // direct repo, not the fleet package — even on the reserved path.
    $this->withBasicAuth('melfina', 'melf_testkey')
        ->get('/plugins/~melfina.git/info/refs')
        ->assertOk();
    expect(is_dir(app(PluginRepo::class)->repoDir($this->agent, 'direct')))->toBeTrue();
});

it('404s the reserved fleet path on a dead fleet key', function () {
    $this->withBasicAuth('melfina', 'melfg_dead')
        ->get('/plugins/~melfina.git/info/refs')
        ->assertNotFound();

    expect(is_dir(app(PluginRepo::class)->globalDir()))->toBeFalse();
});

it('rebuilds the fleet package when the endpoint changes', function () {
    $clone = fn () => $this
        ->withBasicAuth('melfina', $this->fleetKey)
        ->get('/plugins/~melfina.git/info/refs');

    $clone()->assertOk();
    $dir = app(PluginRepo::class)->globalDir();
    $show = fn () => tap(new Process(['git', '--git-dir='.$dir, 'show', 'HEAD:mcp.json']), fn ($p) => $p->run())->getOutput();

    // The packaged URL is https://YOUR_AGENT_NAME:{key}@{host}/mcp — the
    // app.url host:port sits behind the userinfo.
    expect($show())->toContain(parse_url(config('app.url'), PHP_URL_HOST));

    config(['app.url' => 'http://elsewhere.test']);
    $clone()->assertOk();
    expect($show())->toContain('elsewhere.test/mcp');
});

it('never serves the fleet package at a slug-colliding path', function () {
    // An agent literally named "~melfina" slugs to "melfina" — its clone
    // URL is /plugins/melfina.git, distinct from the reserved ~melfina.git.
    $tilda = Agent::factory()->withKey('melf_tildakey')->create(['name' => '~melfina']);

    $this->withBasicAuth('~melfina', $this->fleetKey)
        ->get('/plugins/melfina.git/info/refs')
        ->assertOk();

    $repos = app(PluginRepo::class);
    expect(is_dir($repos->repoDir($tilda, 'fleet')))->toBeTrue()
        ->and(is_dir($repos->globalDir()))->toBeFalse();
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

    $response = $this->actingAs($this->owner)
        ->get(route('console.agents.plugin', $this->agent))
        ->assertOk()
        ->assertHeader('content-disposition');

    // The response is a BinaryFileResponse that never sends in tests, so
    // deleteFileAfterSend hasn't fired — the archive is inspectable.
    $archive = new ZipArchive;
    expect($archive->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();

    $names = collect(range(0, $archive->numFiles - 1))
        ->map(fn (int $i) => $archive->getNameIndex($i));
    $archive->close();

    expect($names)
        ->toContain('mcp.json', 'plugin.json')
        ->toContain('.minimax-plugin/plugin.json', '.claude-plugin/plugin.json', '.mcp.json');
});

it('rejects duplicate agent names on create and rename', function () {
    $this->actingAs($this->owner)
        ->post(route('console.agents.store'), ['name' => $this->agent->name])
        ->assertSessionHasErrors('name');

    $other = Agent::factory()->create(['name' => 'other-agent']);
    $this->actingAs($this->owner)
        ->put(route('console.agents.update', $other), [
            'name' => $this->agent->name,
            'status' => Agent::STATUS_ACTIVE,
        ])
        ->assertSessionHasErrors('name');
});

it('drops plugin artifacts when the agent is disabled', function () {
    $repos = app(PluginRepo::class);
    $this->withBasicAuth('x', 'melf_testkey')->get('/plugins/a.git/info/refs')->assertOk();
    $repos->rebuildPackage($this->agent, 'melf_testkey');

    $this->actingAs($this->owner)
        ->put(route('console.agents.update', $this->agent), [
            'name' => $this->agent->name,
            'status' => Agent::STATUS_DISABLED,
        ]);

    expect(is_dir($repos->repoDir($this->agent, 'direct')))->toBeFalse()
        ->and(is_dir($repos->packageDir($this->agent)))->toBeFalse();
});

it('rejects a rotated-out fleet key on /mcp', function () {
    $old = $this->fleetKey;
    $this->actingAs($this->owner)->post(route('console.mcp-key.store'));

    ($this->mcp)('tools/list', [], $old, ['X-Melfina-Agent-Key' => 'melf_testkey'])
        ->assertUnauthorized();
});

it('404s a direct clone with a disabled agent key', function () {
    Agent::factory()->withKey('melf_deadkey')->disabled()->create();
    $this->withBasicAuth('x', 'melf_deadkey')->get('/plugins/a.git/info/refs')->assertNotFound();
});

it('renders the persona clone URLs on the connect page', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.connect', $this->agent))
        ->assertOk()
        ->assertSee('INSTALL AS PLUGIN')
        ->assertSee(':melfg_…@', false)
        ->assertSee(rawurlencode($this->agent->name), false)
        ->assertSee('/plugins/~melfina.git', false)
        ->assertSee('melfina:melfg_…@', false);
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
    $this->withBasicAuth('melfina', $this->fleetKey)
        ->get('/plugins/~melfina.git/info/refs')->assertOk();
    expect(is_dir($repos->repoDir($this->agent, 'fleet')))->toBeTrue()
        ->and(is_dir($repos->globalDir()))->toBeTrue();

    // Rotation wipes every repo embedding the old key — per-agent and the
    // fleet-wide package alike.
    $this->actingAs($this->owner)->post(route('console.mcp-key.store'));
    expect(is_dir($repos->repoDir($this->agent, 'fleet')))->toBeFalse()
        ->and(is_dir($repos->globalDir()))->toBeFalse();

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
