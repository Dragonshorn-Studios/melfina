<?php

use App\Mcp\PluginRepo;
use App\Models\AdminApiKey;
use App\Models\Agent;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\VfsFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();

    $this->adminKey = function (array $permissions): string {
        return AdminApiKey::issue($this->owner, 'yukihime', $permissions);
    };

    $this->withAdminKey = function (string $bearer): array {
        return ['Authorization' => 'Bearer '.$bearer];
    };
});

// ── status & authentication ─────────────────────────────────────────

it('reports install status to a key with status:read', function () {
    // A non-default version pins the MELFINA_VERSION wiring — asserting
    // against config() itself would be tautological.
    config(['melfina.version' => 'pin-9.9.9']);
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_STATUS_READ]));

    $this->getJson('/api/admin/status', $bearer)
        ->assertOk()
        ->assertJson(['ok' => true, 'product' => 'Melfina', 'status' => 'ok'])
        ->assertJsonPath('version', 'pin-9.9.9');
});

it('rejects missing, wrong-family, and revoked credentials', function () {
    $this->getJson('/api/admin/status')
        ->assertUnauthorized()
        ->assertJson(['ok' => false]);

    // An agent key is a data-plane credential — never an admin one.
    Agent::factory()->withKey('melf_agentkey')->create();
    $this->getJson('/api/admin/status', ['Authorization' => 'Bearer melf_agentkey'])
        ->assertUnauthorized();

    // Nor is a fleet key.
    $fleetKey = $this->owner->rotateMcpKey();
    $this->getJson('/api/admin/status', ['Authorization' => 'Bearer '.$fleetKey])
        ->assertUnauthorized();

    // Revocation kills the admin credential immediately.
    $revoked = ($this->adminKey)([AdminApiKey::PERMISSION_STATUS_READ]);
    $this->owner->adminApiKeys()->sole()->revoke();
    $this->getJson('/api/admin/status', ($this->withAdminKey)($revoked))
        ->assertUnauthorized();
});

it('accepts the admin key over http basic and rejects other families there', function () {
    $plaintext = ($this->adminKey)([AdminApiKey::PERMISSION_STATUS_READ]);

    // The username is ignored — the Basic password carries the credential,
    // for clients that can only configure a URL.
    $this->withBasicAuth('yukihime', $plaintext)
        ->getJson('/api/admin/status')
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->withBasicAuth('yukihime', 'melf_not_an_admin_key')
        ->getJson('/api/admin/status')
        ->assertUnauthorized();
});

it('answers only the endpoints a key has permissions for', function () {
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_STATUS_READ]));

    $this->getJson('/api/admin/agents', $bearer)
        ->assertForbidden()
        ->assertJson(['ok' => false, 'error' => 'This key lacks the agents:read permission.']);
});

it('enforces permissions transport-independently over http basic', function () {
    $reader = ($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_READ]);
    $statusOnly = ($this->adminKey)([AdminApiKey::PERMISSION_STATUS_READ]);

    $this->withBasicAuth('x', $reader)
        ->getJson('/api/admin/agents')
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->withBasicAuth('x', $statusOnly)
        ->getJson('/api/admin/agents')
        ->assertForbidden()
        ->assertJson(['ok' => false, 'error' => 'This key lacks the agents:read permission.']);
});

// ── the never-tools guarantee ───────────────────────────────────────

it('never lets an admin key call tools or reach the mcp endpoint', function () {
    $bearer = ($this->withAdminKey)(($this->adminKey)(AdminApiKey::permissionChoices()));

    $this->getJson('/api/tools', $bearer)->assertUnauthorized();
    $this->postJson('/api/tools/list_files', [], $bearer)->assertUnauthorized();
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ], ['Accept' => 'application/json'] + $bearer)->assertUnauthorized();
});

// ── listing & inspection ────────────────────────────────────────────

it('lists brains with counts, last activity, and knowledge attribution', function () {
    $busy = Agent::factory()->create(['name' => 'busy']);
    VfsFile::factory()->count(2)->create(['agent_id' => $busy->id]);
    $busy->remember('first memory');
    KnowledgeEntry::factory()->count(3)->create(['updated_by' => 'busy']);
    $idle = Agent::factory()->disabled()->create(['name' => 'idle']);

    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_READ]));

    $response = $this->getJson('/api/admin/agents', $bearer)
        ->assertOk()
        ->assertJson(['ok' => true]);

    $agents = collect($response->json('agents'));
    expect($agents->pluck('name')->all())->toBe(['busy', 'idle']);

    $busyPayload = $agents->firstWhere('name', 'busy');
    expect($busyPayload['slug'])->toBe('busy')
        ->and($busyPayload['status'])->toBe('active')
        ->and($busyPayload['counts'])->toBe(['memories' => 1, 'files' => 2, 'knowledge' => 3])
        ->and($busyPayload['last_activity'])->not->toBeNull();

    $idlePayload = $agents->firstWhere('name', 'idle');
    expect($idlePayload['status'])->toBe('disabled')
        ->and($idlePayload['counts'])->toBe(['memories' => 0, 'files' => 0, 'knowledge' => 0])
        ->and($idlePayload['last_activity'])->toBeNull();
});

it('reports the newer of the last file write and the last memory as last activity', function () {
    $fileNewer = Agent::factory()->create(['name' => 'file-newer']);
    VfsFile::factory()->create(['agent_id' => $fileNewer->id, 'updated_at' => '2026-01-02 12:00:00']);
    $fileNewer->memories = [['at' => '2026-01-01T08:00:00+00:00', 'text' => 'older memory']];
    $fileNewer->save();

    $memoryNewer = Agent::factory()->create(['name' => 'memory-newer']);
    VfsFile::factory()->create(['agent_id' => $memoryNewer->id, 'updated_at' => '2026-01-01 08:00:00']);
    $memoryNewer->memories = [['at' => '2026-01-02T12:00:00+00:00', 'text' => 'newer memory']];
    $memoryNewer->save();

    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_READ]));

    $byName = collect($this->getJson('/api/admin/agents', $bearer)->assertOk()->json('agents'))->keyBy('name');

    expect(Carbon::parse($byName['file-newer']['last_activity'])->equalTo(Carbon::parse('2026-01-02 12:00:00')))->toBeTrue()
        ->and(Carbon::parse($byName['memory-newer']['last_activity'])->equalTo(Carbon::parse('2026-01-02 12:00:00')))->toBeTrue();
});

it('keeps knowledge attribution across a console rename', function () {
    $agent = Agent::factory()->create(['name' => 'old-name']);
    KnowledgeEntry::factory()->count(2)->create(['updated_by' => 'old-name']);
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_READ]));

    $before = collect($this->getJson('/api/admin/agents', $bearer)->assertOk()->json('agents'))
        ->firstWhere('name', 'old-name');
    expect($before['counts']['knowledge'])->toBe(2);

    // The graph's only per-agent attribution is the name string — a
    // rename must carry it, or the count orphans under the old name.
    $this->actingAs($this->owner)
        ->put(route('console.agents.update', $agent), [
            'name' => 'new-name',
            'status' => $agent->status,
        ])
        ->assertRedirect(route('console.agents.show', $agent));

    $after = collect($this->getJson('/api/admin/agents', $bearer)->assertOk()->json('agents'))
        ->firstWhere('name', 'new-name');
    expect($after['counts']['knowledge'])->toBe(2)
        ->and(KnowledgeEntry::query()->where('updated_by', 'old-name')->count())->toBe(0);
});

it('shows one brain in detail and 404s unknown ids', function () {
    $agent = Agent::factory()->create(['name' => 'detail', 'description' => 'the one']);
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_READ]));

    $this->getJson("/api/admin/agents/{$agent->id}", $bearer)
        ->assertOk()
        ->assertJson(['ok' => true])
        ->assertJsonPath('agent.name', 'detail')
        ->assertJsonPath('agent.description', 'the one')
        ->assertJsonPath('agent.counts.memories', 0)
        ->assertJsonPath('agent.key_issued_at', null);

    $this->getJson('/api/admin/agents/999', $bearer)
        ->assertNotFound()
        ->assertJson(['ok' => false, 'error' => 'Unknown agent.']);
});

// ── provisioning ────────────────────────────────────────────────────

it('creates a brain with a persona seed and no key', function () {
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_WRITE]));

    $this->postJson('/api/admin/agents', [
        'name' => 'yuki-01',
        'description' => 'provisioned brain',
        'persona' => 'You are a fleet navigator.',
    ], $bearer)
        ->assertCreated()
        ->assertJson(['ok' => true, 'agent' => [
            'name' => 'yuki-01',
            'slug' => 'yuki-01',
            'status' => 'active',
        ]])
        ->assertJsonPath('agent.id', Agent::where('name', 'yuki-01')->sole()->id);

    $agent = Agent::where('name', 'yuki-01')->sole();
    expect($agent->description)->toBe('provisioned brain')
        ->and($agent->persona)->toBe('You are a fleet navigator.')
        // minting stays behind keys:mint — a write-only consumer can't mint
        ->and($agent->key_hash)->toBeNull();
});

it('rejects missing and duplicate brain names with the shared error envelope', function () {
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_WRITE]));
    Agent::factory()->create(['name' => 'taken']);

    $this->postJson('/api/admin/agents', ['description' => 'no name'], $bearer)
        ->assertUnprocessable()
        ->assertJson(['ok' => false, 'error' => 'Invalid input.'])
        ->assertJsonStructure(['fields' => ['name']]);

    $this->postJson('/api/admin/agents', ['name' => 'taken'], $bearer)
        ->assertUnprocessable()
        ->assertJson(['ok' => false, 'error' => 'Invalid input.']);
});

it('accepts a description up to the column-backed limit and no further', function () {
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_WRITE]));

    // 500 is the contract on both surfaces; the column is widened to
    // match, so a contract-valid blurb can't die on the INSERT.
    $this->postJson('/api/admin/agents', ['name' => 'blurb', 'description' => str_repeat('a', 500)], $bearer)
        ->assertCreated();
    expect(Agent::where('name', 'blurb')->sole()->description)->toHaveLength(500);

    $this->postJson('/api/admin/agents', ['name' => 'blurb-2', 'description' => str_repeat('a', 501)], $bearer)
        ->assertUnprocessable()
        ->assertJson(['ok' => false, 'error' => 'Invalid input.']);
});

it('declares the description column at the 500-char contract', function () {
    // SQLite — the CI database — drops varchar lengths from its DDL, so
    // the boundary test above cannot catch a wrongly-sized migration.
    // Assert the declared length wherever the driver exposes one, so
    // the gap closes the day the suite runs against a strict database.
    $column = collect(DB::select('PRAGMA table_info(agents)'))
        ->firstWhere('name', 'description');
    expect($column?->type)->toBe('varchar');

    if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $length = (int) DB::selectOne(
            'select character_maximum_length as length from information_schema.columns'
            ." where table_schema = ? and table_name = 'agents' and column_name = 'description'",
            [DB::getDatabaseName()],
        )?->length;

        expect($length)->toBe(500);
    }
});

it('rejects the operator-reserved name owner', function () {
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_WRITE]));

    // 'owner' is the knowledge graph's author stamp for the operator's
    // own writes — an agent with that name would absorb them.
    $this->postJson('/api/admin/agents', ['name' => 'Owner'], $bearer)
        ->assertUnprocessable()
        ->assertJson(['ok' => false, 'error' => 'Invalid input.']);

    $this->assertDatabaseMissing('agents', ['name' => 'Owner']);

    // The same rule covers the console rename path.
    $agent = Agent::factory()->create(['name' => 'renamable']);
    $this->actingAs($this->owner)
        ->put(route('console.agents.update', $agent), ['name' => 'owner', 'status' => $agent->status])
        ->assertSessionHasErrors('name');
});

it('rejects an agent name that slugs to the public repo name', function () {
    $reserved = Str::beforeLast(app(PluginRepo::class)->publicRepoName(), '.git');
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_WRITE]));

    $this->postJson('/api/admin/agents', ['name' => $reserved], $bearer)
        ->assertUnprocessable()
        ->assertJson(['ok' => false, 'error' => 'Invalid input.']);

    $this->assertDatabaseMissing('agents', ['name' => $reserved]);
});

it('falls back to the agent slug for names that slug to nothing', function () {
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_WRITE]));

    $this->postJson('/api/admin/agents', ['name' => '###'], $bearer)
        ->assertCreated()
        ->assertJsonPath('agent.slug', 'agent');
});

it('refuses to create brains without agents:write', function () {
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_READ]));

    $this->postJson('/api/admin/agents', ['name' => 'nope'], $bearer)
        ->assertForbidden()
        ->assertJson(['ok' => false, 'error' => 'This key lacks the agents:write permission.']);
});

it('mints and rotates the agent key, storing only the hash', function () {
    $agent = Agent::factory()->create(['name' => 'keyless']);
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_KEYS_MINT]));

    $first = $this->postJson("/api/admin/agents/{$agent->id}/key", [], $bearer)
        ->assertOk()
        ->assertJson(['ok' => true])
        ->json('key');

    expect($first)->toStartWith('melf_')
        ->and($agent->fresh()->key_hash)->toBe(Agent::hashKey($first))
        ->and($agent->fresh()->key_hash)->not->toBe($first);

    // A second mint is a rotation — the old credential stops resolving.
    $second = $this->postJson("/api/admin/agents/{$agent->id}/key", [], $bearer)->json('key');

    expect($second)->toStartWith('melf_')
        ->and($second)->not->toBe($first)
        ->and(Agent::forKey($first))->toBeNull()
        ->and(Agent::forKey($second)?->id)->toBe($agent->id);

    $this->postJson('/api/admin/agents/999/key', [], $bearer)
        ->assertNotFound()
        ->assertJson(['ok' => false, 'error' => 'Unknown agent.']);
});

it('refuses to mint without keys:mint', function () {
    $agent = Agent::factory()->create();
    $bearer = ($this->withAdminKey)(($this->adminKey)([AdminApiKey::PERMISSION_AGENTS_WRITE]));

    $this->postJson("/api/admin/agents/{$agent->id}/key", [], $bearer)
        ->assertForbidden()
        ->assertJson(['ok' => false, 'error' => 'This key lacks the keys:mint permission.']);
});

it('stamps key_issued_at on the detail once a key is minted', function () {
    $agent = Agent::factory()->create(['name' => 'stamp']);
    $bearer = ($this->withAdminKey)(($this->adminKey)([
        AdminApiKey::PERMISSION_KEYS_MINT,
        AdminApiKey::PERMISSION_AGENTS_READ,
    ]));

    $this->getJson("/api/admin/agents/{$agent->id}", $bearer)
        ->assertJsonPath('agent.key_issued_at', null);

    $this->postJson("/api/admin/agents/{$agent->id}/key", [], $bearer)->assertOk();

    $detail = $this->getJson("/api/admin/agents/{$agent->id}", $bearer)->assertOk();
    expect($detail->json('agent.key_issued_at'))->not->toBeNull();
});

it('keeps the create and the shown-once key when packaging fails', function () {
    $bearer = ($this->withAdminKey)(($this->adminKey)([
        AdminApiKey::PERMISSION_AGENTS_WRITE,
        AdminApiKey::PERMISSION_KEYS_MINT,
    ]));

    // Packaging is a side effect — a filesystem failure must not eat the
    // 201 create or the shown-once plaintext. Partial mock: only the
    // sync throws; the reserved-name rule still reads the real repo name.
    $this->partialMock(PluginRepo::class, fn ($mock) => $mock->shouldReceive('syncForAgent')->andThrow(new RuntimeException('disk gone')));

    $this->postJson('/api/admin/agents', ['name' => 'yuki-02'], $bearer)
        ->assertCreated()
        ->assertJsonPath('agent.name', 'yuki-02');
    $this->assertDatabaseHas('agents', ['name' => 'yuki-02']);

    $agent = Agent::where('name', 'yuki-02')->sole();
    $key = $this->postJson("/api/admin/agents/{$agent->id}/key", [], $bearer)
        ->assertOk()
        ->assertJson(['ok' => true])
        ->json('key');

    expect($key)->toStartWith('melf_')
        ->and($agent->fresh()->key_hash)->toBe(Agent::hashKey($key));
});

// ── console management ──────────────────────────────────────────────

it('issues an admin key from the console and flashes it once', function () {
    $response = $this->actingAs($this->owner)
        ->post(route('console.admin-keys.store'), [
            'admin_name' => 'yukihime',
            'permissions' => ['agents:read', 'keys:mint'],
        ])
        ->assertRedirect(route('console.dashboard'));

    $response->assertSessionHas('plaintext_admin_key');
    $plaintext = session('plaintext_admin_key');

    $key = $this->owner->adminApiKeys()->sole();
    expect($key->name)->toBe('yukihime')
        ->and($key->permissions)->toBe(['agents:read', 'keys:mint'])
        ->and($key->revoked_at)->toBeNull()
        ->and($plaintext)->toStartWith('melfa_')
        // only the hash persists — the plaintext is never stored
        ->and($key->key_hash)->toBe(Agent::hashKey($plaintext));
});

it('rolls same-name keys from the console', function () {
    $first = AdminApiKey::issue($this->owner, 'yukihime', [AdminApiKey::PERMISSION_STATUS_READ]);

    $this->actingAs($this->owner)
        ->post(route('console.admin-keys.store'), [
            'admin_name' => 'yukihime',
            'permissions' => [AdminApiKey::PERMISSION_STATUS_READ],
        ])
        ->assertSessionHasNoErrors();

    expect($this->owner->adminApiKeys()->whereNull('revoked_at')->sole()->name)->toBe('yukihime')
        ->and($this->owner->adminApiKeys()->whereNotNull('revoked_at')->count())->toBe(1)
        ->and(AdminApiKey::forKey($first))->toBeNull();
});

it('defaults the console key name', function () {
    $this->actingAs($this->owner)
        ->post(route('console.admin-keys.store'), ['permissions' => [AdminApiKey::PERMISSION_STATUS_READ]])
        ->assertSessionHasNoErrors();

    expect($this->owner->adminApiKeys()->sole()->name)->toBe('default');
});

it('revokes an admin key from the console and kills its api access', function () {
    $plaintext = AdminApiKey::issue($this->owner, 'yukihime', AdminApiKey::permissionChoices());
    $key = $this->owner->adminApiKeys()->sole();

    $this->actingAs($this->owner)
        ->delete(route('console.admin-keys.destroy', $key))
        ->assertRedirect(route('console.dashboard'));

    $this->getJson('/api/admin/status', ($this->withAdminKey)($plaintext))
        ->assertUnauthorized();
});

it('offers the admin key section on the dashboard', function () {
    AdminApiKey::issue($this->owner, 'yukihime', [AdminApiKey::PERMISSION_AGENTS_READ]);

    $this->actingAs($this->owner)
        ->get(route('console.dashboard'))
        ->assertOk()
        ->assertSee('ADMIN API KEYS')
        ->assertSee('value="agents:read"', false)
        ->assertSee('value="keys:mint"', false)
        ->assertSee('yukihime');
});

it('keeps the console owner-only', function () {
    $this->post(route('console.admin-keys.store'), ['permissions' => ['status:read']])
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create(['is_owner' => false]))
        ->post(route('console.admin-keys.store'), ['permissions' => ['status:read']])
        ->assertForbidden();
});

it('requires at least one known permission', function () {
    $this->actingAs($this->owner)
        ->post(route('console.admin-keys.store'), ['permissions' => []])
        ->assertSessionHasErrors('permissions');

    $this->actingAs($this->owner)
        ->post(route('console.admin-keys.store'), ['permissions' => ['nonsense']])
        ->assertSessionHasErrors('permissions.0');
});

it('rejects console key names outside the safe alphabet', function () {
    $this->actingAs($this->owner)
        ->post(route('console.admin-keys.store'), [
            'admin_name' => 'bad name!',
            'permissions' => [AdminApiKey::PERMISSION_STATUS_READ],
        ])
        ->assertSessionHasErrors('admin_name');
});
