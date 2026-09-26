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
        ->assertJson(['error' => 'agent key required']);
});

it('resolves the persona from the agent key header', function () {
    ($this->mcp)('tools/list', [], $this->fleetKey, ['X-Melfina-Agent-Key' => 'melf_testkey'])
        ->assertOk();
});

it('resolves the persona from the agent_key query param', function () {
    $this->postJson('/mcp?agent_key=melf_testkey', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$this->fleetKey])->assertOk();
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

it('serves the plugin repo over the dumb git protocol', function () {
    $this->get('/plugins/melf_testkey.git/info/refs')->assertOk();
    $this->get('/plugins/melf_testkey.git/HEAD')->assertOk();

    // BinaryFileResponse content isn't captured in tests — assert on the
    // built repo files behind the 200s.
    $dir = app(PluginRepo::class)->repoDir($this->agent);

    expect(file_get_contents($dir.'/info/refs'))->toContain('refs/heads/main');
    expect(file_get_contents($dir.'/HEAD'))->toContain('refs/heads/main');

    $pack = collect(explode("\n", trim((string) file_get_contents($dir.'/objects/info/packs'))))
        ->map(fn ($line) => trim(str_replace('P ', '', $line)))
        ->filter()
        ->first();

    $this->get('/plugins/melf_testkey.git/objects/pack/'.$pack)->assertOk();
});

it('serves the fleet-form repo URL with both keys embedded', function () {
    $this->get("/plugins/{$this->fleetKey}/melf_testkey.git/info/refs")->assertOk();

    $repo = app(PluginRepo::class)->repoDir($this->agent);
    $mcp = new Process(['git', '--git-dir='.$repo, 'show', 'HEAD:mcp.json']);
    $mcp->run();

    expect($mcp->getOutput())
        ->toContain('Bearer '.$this->fleetKey)
        ->toContain('melf_testkey');
});

it('404s plugin repo requests with dead credentials', function () {
    $this->get('/plugins/melf_deadkey.git/info/refs')->assertNotFound();
    $this->get('/plugins/melfg_dead/melf_testkey.git/info/refs')->assertNotFound();
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

it('404s the plugin zip before a package exists', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.plugin', $this->agent))
        ->assertNotFound();
});

it('drops plugin repos embedding a rotated key', function () {
    $this->get("/plugins/{$this->fleetKey}/melf_testkey.git/info/refs")->assertOk();
    $repos = app(PluginRepo::class);
    expect(is_dir($repos->repoDir($this->agent)))->toBeTrue();

    $this->actingAs($this->owner)->post(route('console.mcp-key.store'));
    expect(is_dir($repos->repoDir($this->agent)))->toBeFalse();

    $this->get('/plugins/melf_testkey.git/info/refs')->assertOk();
    expect(is_dir($repos->repoDir($this->agent)))->toBeTrue();

    $this->actingAs($this->owner)
        ->post(route('console.agents.rotate', $this->agent));
    expect(is_dir($repos->repoDir($this->agent)))->toBeFalse();
});
