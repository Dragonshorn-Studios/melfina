<?php

use App\Mcp\PluginRepo;
use App\Mcp\ToolCatalog;
use App\Models\Agent;
use App\Models\AgentApiKey;
use App\Models\User;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->withKey()->create(['name' => 'grokbot']);
});

// ── console issuance ────────────────────────────────────────────────

it('issues a scoped key from the console and flashes it once', function () {
    $response = $this->actingAs($this->owner)
        ->post(route('console.agents.api-keys.store', $this->agent), [
            'key_name' => 'cloud-minimax',
            'scopes' => ['knowledge:read', 'files:read'],
        ])
        ->assertRedirect(route('console.agents.show', $this->agent));

    $response->assertSessionHas('plaintext_key');
    $plaintext = session('plaintext_key');

    $key = $this->agent->apiKeys()->sole();
    expect($key->name)->toBe('cloud-minimax')
        ->and($key->scopes)->toBe(['knowledge:read', 'files:read'])
        ->and($key->revoked_at)->toBeNull()
        ->and($plaintext)->toStartWith('melfs_')
        // only the hash persists — the plaintext is never stored
        ->and($key->key_hash)->not->toBe($plaintext)
        ->and($key->key_hash)->toBe(Agent::hashKey($plaintext));
});

it('issues preset scopes from the console and stores them as-is', function () {
    $this->actingAs($this->owner)
        ->post(route('console.agents.api-keys.store', $this->agent), [
            'key_name' => 'reader',
            'scopes' => ['all:read'],
        ])
        ->assertSessionHasNoErrors();

    $key = $this->agent->apiKeys()->sole();
    expect($key->scopes)->toBe(['all:read'])
        ->and($key->allows('files:read'))->toBeTrue()
        ->and($key->allows('knowledge:read'))->toBeTrue()
        ->and($key->allows('files:write'))->toBeFalse()
        ->and($key->allows('knowledge:write'))->toBeFalse();
});

it('lets all:write cover every concrete scope', function () {
    AgentApiKey::issue($this->agent, 'full', ['all:write']);
    $key = $this->agent->apiKeys()->sole();

    foreach (app(ToolCatalog::class)->scopes() as $scope) {
        expect($key->allows($scope))->toBeTrue();
    }
});

it('offers the preset scopes in the issue form', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.show', $this->agent))
        ->assertOk()
        ->assertSee('value="all:read"', false)
        ->assertSee('value="all:write"', false)
        ->assertSee('all read + write');
});

it('requires at least one valid scope', function () {
    $this->actingAs($this->owner)
        ->post(route('console.agents.api-keys.store', $this->agent), [
            'key_name' => 'x',
            'scopes' => [],
        ])
        ->assertSessionHasErrors('scopes');

    // A made-up scope name is refused — the checkbox list is the contract.
    $this->actingAs($this->owner)
        ->post(route('console.agents.api-keys.store', $this->agent), [
            'key_name' => 'x',
            'scopes' => ['knowledge:read', 'admin:all'],
        ])
        ->assertSessionHasErrors('scopes.1');

    expect($this->agent->apiKeys()->count())->toBe(0);
});

it('is owner-only', function () {
    $plain = User::factory()->create();

    $this->actingAs($plain)
        ->post(route('console.agents.api-keys.store', $this->agent), [
            'key_name' => 'x',
            'scopes' => ['knowledge:read'],
        ])
        ->assertForbidden();

    $this->postJson('/api/tools/knowledge_lookup', ['query' => 'x'])->assertUnauthorized();
});

it('revokes a key so it stops authenticating', function () {
    $key = AgentApiKey::issue($this->agent, 'cloud', ['knowledge:read']);
    $model = AgentApiKey::query()->sole();

    $this->actingAs($this->owner)
        ->delete(route('console.agents.api-keys.destroy', [$this->agent, $model]))
        ->assertRedirect(route('console.agents.show', $this->agent));

    expect($model->fresh()->revoked_at)->not->toBeNull();

    $this->postJson('/api/tools/knowledge_lookup', ['query' => 'x'], [
        'Authorization' => 'Bearer '.$key,
    ])->assertUnauthorized();
});

it('cannot revoke another agent\'s key through the scoped route', function () {
    $other = Agent::factory()->withKey()->create(['name' => 'otherbot']);
    AgentApiKey::issue($other, 'x', ['files:read']);
    $foreignModel = $other->apiKeys()->sole();

    $this->actingAs($this->owner)
        ->delete(route('console.agents.api-keys.destroy', [$this->agent, $foreignModel]))
        ->assertNotFound();

    expect($foreignModel->fresh()->revoked_at)->toBeNull();
});

it('shows issued keys on the agent page', function () {
    AgentApiKey::issue($this->agent, 'cloud-minimax', ['knowledge:read']);

    $this->actingAs($this->owner)
        ->get(route('console.agents.show', $this->agent))
        ->assertOk()
        ->assertSee('cloud-minimax')
        ->assertSee('knowledge:read');
});

// ── generated skills in every export ────────────────────────────────

it('ships a generated skill per tool plus the manifest in the public package', function () {
    $files = app(PluginRepo::class)->publicPackageFiles();
    $tools = app(ToolCatalog::class)->all();

    // One skills/melfina-<tool>/SKILL.md per catalogued tool.
    foreach ($tools as $tool) {
        $dir = 'skills/melfina-'.str_replace('_', '-', $tool['name']).'/SKILL.md';
        expect($files)->toHaveKey($dir);

        $skill = $files[$dir];

        // MCP-first precedence + the no-dual-call rule, verbatim.
        expect($skill)->toContain('If the Melfina MCP server is available, use the corresponding MCP tool instead of this skill')
            ->toContain('Use this skill only as an HTTP fallback when MCP is unavailable')
            ->toContain('Do not call both MCP and HTTP for the same operation')
            // endpoint, auth scope, and request schema
            ->toContain('POST '.config('app.url').'/api/tools/'.$tool['name'])
            ->toContain($tool['scope'])
            ->toContain('"properties"');
    }

    // The machine-readable manifest covers the whole surface.
    $manifest = json_decode($files['melfina-api.json'], true);
    expect(collect($manifest['tools'])->pluck('name')->all())
        ->toEqualCanonicalizing(collect($tools)->pluck('name')->all())
        ->and($manifest['endpoint'])->toBe(config('app.url').'/api/tools/{tool}');

    // The umbrella skill carries the top-level instruction.
    expect($files['skills/melfina/SKILL.md'])
        ->toContain('Before using a Melfina HTTP skill, check whether the Melfina MCP server is available')
        ->toContain('Prefer MCP whenever possible');

    // Every generated skill is registered in the MiniMax manifest.
    $minimax = json_decode($files['.minimax-plugin/plugin.json'], true);
    foreach (collect($tools)->map(fn ($t) => 'skills/melfina-'.str_replace('_', '-', $t['name']).'/SKILL.md') as $path) {
        expect($minimax['skills'])->toContain($path);
    }
});

it('ships the same skills inside the credentialed clone repos', function () {
    $repos = app(PluginRepo::class);

    foreach (['direct', 'fleet'] as $mode) {
        $dir = $repos->ensureBuilt($this->agent, $mode, 'melf_testkey');
        $show = fn (string $f) => tap(new Process(['git', '--git-dir='.$dir, 'show', 'HEAD:'.$f]), fn ($p) => $p->run())->getOutput();

        expect($show('skills/melfina-knowledge-lookup/SKILL.md'))
            ->toContain('If the Melfina MCP server is available, use the corresponding MCP tool instead of this skill')
            ->toContain('/api/tools/knowledge_lookup')
            ->and($show('melfina-api.json'))->toContain('knowledge_lookup');
    }

    // …and the flat ZIP package for the same agent.
    $repos->rebuildPackage($this->agent, 'melf_testkey');
    $dir = $repos->packageDir($this->agent);

    expect(file_get_contents($dir.'/skills/melfina-knowledge-lookup/SKILL.md'))
        ->toContain('If the Melfina MCP server is available')
        ->and(is_file($dir.'/melfina-api.json'))->toBeTrue();
});

it('ships the generated skills in the fleet package too', function () {
    $repos = app(PluginRepo::class);
    $dir = $repos->ensureGlobal('melfg_fleetkey');

    $show = fn (string $f) => tap(new Process(['git', '--git-dir='.$dir, 'show', 'HEAD:'.$f]), fn ($p) => $p->run())->getOutput();

    expect($show('skills/melfina-read-file/SKILL.md'))->toContain('/api/tools/read_file')
        ->and($show('skills/melfina/SKILL.md'))->toContain('Prefer MCP whenever possible');
});

it('never bakes credentials into generated skill artifacts', function () {
    $files = app(PluginRepo::class)->publicPackageFiles();

    foreach ($files as $path => $contents) {
        if (str_starts_with($path, 'skills/') || $path === 'melfina-api.json') {
            // doc text mentions the key *forms* (`melf_…`); what must not
            // appear is a real key value — prefix + identifier chars.
            expect($contents)
                ->not->toMatch('/melf[gs]?_[A-Za-z0-9]/')
                ->toContain('$MELFINA_API_KEY');
        }
    }
});
