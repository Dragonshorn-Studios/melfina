<?php

use App\Mcp\Servers\MelfinaServer;
use App\Mcp\ToolCatalog;
use App\Models\Agent;
use App\Models\AgentApiKey;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->withKey()->create(['name' => 'grokbot']);

    $this->call = function (string $tool, array $args = [], ?string $bearer = 'melf_testkey', array $headers = []) {
        return $this->postJson('/api/tools/'.$tool, $args, array_merge(
            $bearer === null ? [] : ['Authorization' => 'Bearer '.$bearer],
            $headers,
        ));
    };
});

it('invokes a tool end-to-end with a dedicated agent key', function () {
    ($this->call)('write_file', ['path' => 'notes/x.txt', 'content' => 'hi'])->assertOk()
        ->assertJson(['ok' => true]);

    $response = ($this->call)('read_file', ['path' => 'notes/x.txt'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    // read_file returns raw text — it surfaces as the scalar `result`.
    expect($response->json('result'))->toBe('hi')
        ->and($this->agent->files()->where('path', 'notes/x.txt')->exists())->toBeTrue();
});

it('round-trips the knowledge tool over HTTP', function () {
    $created = ($this->call)('knowledge_upsert', [
        'entry' => 'Yukine',
        'type' => 'project',
        'summary' => 'Discord bridge',
    ])->assertOk();

    expect($created->json('result.created'))->toBeTrue();

    ($this->call)('knowledge_lookup', ['query' => 'yukine'])
        ->assertOk()
        ->assertJsonPath('result.found', true)
        ->assertJsonPath('result.name', 'Yukine');
});

it('requires authentication', function () {
    ($this->call)('list_files', [], null)->assertUnauthorized()
        ->assertJson(['ok' => false]);

    ($this->call)('list_files', [], 'melf_dead')->assertUnauthorized();
    ($this->call)('list_files', [], 'melfs_dead')->assertUnauthorized();
    ($this->call)('list_files', [], 'melfg_dead')->assertUnauthorized();
});

it('404s unknown tools and tools outside the scoped surface', function () {
    ($this->call)('not_a_tool')->assertNotFound()->assertJson(['ok' => false]);
    ($this->call)('Tools-Call')->assertNotFound();
});

it('returns 422 with field errors on invalid arguments', function () {
    ($this->call)('read_file', [])
        ->assertUnprocessable()
        ->assertJson(['ok' => false])
        ->assertJsonStructure(['fields' => ['path']]);
});

it('rejects a non-object body', function () {
    $this->call('POST', '/api/tools/list_files', server: [
        'HTTP_AUTHORIZATION' => 'Bearer melf_testkey',
        'CONTENT_TYPE' => 'application/json',
    ], content: json_encode(['a', 'b']))->assertUnprocessable();
});

it('rejects a malformed JSON body', function () {
    $this->call('POST', '/api/tools/list_files', server: [
        'HTTP_AUTHORIZATION' => 'Bearer melf_testkey',
        'CONTENT_TYPE' => 'application/json',
    ], content: '{not json')->assertUnprocessable();
});

it('returns raw file content verbatim, even when it parses as JSON', function () {
    ($this->call)('write_file', ['path' => 'doc.json', 'content' => '{"a": 1}'])->assertOk();

    ($this->call)('read_file', ['path' => 'doc.json'])
        ->assertOk()
        ->assertJson(['ok' => true, 'result' => '{"a": 1}', 'resultType' => 'text']);

    // A JSON-payload tool still decodes its result.
    ($this->call)('list_files', [])
        ->assertOk()
        ->assertJson(['resultType' => 'json'])
        ->assertJsonPath('result.0', 'doc.json');
});

it('maps tool-level errors to a 400 {ok:false} envelope', function () {
    ($this->call)('read_file', ['path' => 'missing.txt'])
        ->assertBadRequest()
        ->assertJson(['ok' => false, 'error' => 'File not found.']);
});

it('keeps every MCP tool on the scoped HTTP surface', function () {
    // A tool added to TOOLS without a SCOPES entry would silently vanish
    // from HTTP + generated skills while staying on MCP.
    expect(app(ToolCatalog::class)->all())
        ->toHaveCount(count(MelfinaServer::TOOLS));
});

it('lists the tool surface with scopes', function () {
    $response = $this->getJson('/api/tools', ['Authorization' => 'Bearer melf_testkey'])->assertOk();

    $tools = collect($response->json('tools'));
    expect($tools->pluck('name'))->toContain('knowledge_lookup')
        ->and($tools->firstWhere('name', 'knowledge_lookup')['scope'])->toBe('knowledge:read')
        ->and($tools->firstWhere('name', 'knowledge_lookup')['inputSchema']['required'])->toContain('query');
});

// ── scoped API keys ─────────────────────────────────────────────────

it('lets a scoped key call only its allowed scopes', function () {
    $key = AgentApiKey::issue($this->agent, 'cloud', ['knowledge:read']);

    ($this->call)('knowledge_lookup', ['query' => 'x'], $key)->assertOk();

    ($this->call)('read_file', ['path' => 'x'], $key)
        ->assertForbidden()
        ->assertJson(['ok' => false, 'error' => 'This key lacks the files:read scope.']);

    ($this->call)('write_file', ['path' => 'x', 'content' => 'y'], $key)->assertForbidden();
});

it('honours the all:read and all:write scope presets', function () {
    $reader = AgentApiKey::issue($this->agent, 'reader', ['all:read']);

    ($this->call)('list_files', [], $reader)->assertOk();
    ($this->call)('knowledge_lookup', ['query' => 'x'], $reader)->assertOk();
    ($this->call)('write_file', ['path' => 'x', 'content' => 'y'], $reader)->assertForbidden();

    $full = AgentApiKey::issue($this->agent, 'full', ['all:write']);

    ($this->call)('write_file', ['path' => 'x', 'content' => 'y'], $full)->assertOk();
    ($this->call)('knowledge_lookup', ['query' => 'x'], $full)->assertOk();
});

it('rejects revoked, disabled-agent, and unknown scoped keys', function () {
    $key = AgentApiKey::issue($this->agent, 'cloud', ['knowledge:read']);
    AgentApiKey::query()->first()->revoke();
    ($this->call)('knowledge_lookup', ['query' => 'x'], $key)->assertUnauthorized();

    $key2 = AgentApiKey::issue($this->agent, 'cloud2', ['files:read']);
    $this->agent->update(['status' => Agent::STATUS_DISABLED]);
    ($this->call)('list_files', [], $key2)->assertUnauthorized();
    $this->agent->update(['status' => Agent::STATUS_ACTIVE]);
});

it('scopes calls to the key\'s own agent', function () {
    $other = Agent::factory()->withKey('melf_otherkey')->create();
    $other->files()->create(['path' => 'secret.txt', 'content' => 'not yours']);

    $key = AgentApiKey::issue($other, 'cloud', ['files:read']);

    ($this->call)('read_file', ['path' => 'secret.txt'], $key)
        ->assertOk()
        ->assertJsonPath('result', 'not yours');

    // …but the same key can't reach another agent's tree — `name` is a
    // claim that must match the key's agent, not a selector.
    ($this->call)('list_files', ['name' => 'grokbot'], $key)->assertUnauthorized();
});

// ── fleet key ───────────────────────────────────────────────────────

it('resolves the persona for fleet-key calls from header, query, or body name', function () {
    $fleet = $this->owner->rotateMcpKey();

    ($this->call)('list_files', [], $fleet, ['X-Melfina-Agent-Key' => 'grokbot'])->assertOk();

    $this->postJson('/api/tools/list_files?agent_key=grokbot', [], [
        'Authorization' => 'Bearer '.$fleet,
    ])->assertOk();

    // The `name` argument is the persona channel — same as over MCP.
    ($this->call)('list_files', ['name' => 'grokbot'], $fleet)->assertOk();

    // Keys stay out of query strings — the `melf_` form is ignored there.
    $this->postJson('/api/tools/list_files?agent_key=melf_testkey', [], [
        'Authorization' => 'Bearer '.$fleet,
    ])->assertUnauthorized();
});

it('requires a persona for fleet-key tool calls but lists tools persona-less', function () {
    $fleet = $this->owner->rotateMcpKey();

    ($this->call)('list_files', [], $fleet)
        ->assertUnauthorized()
        ->assertJson(['ok' => false, 'error' => 'Agent persona required.']);

    $this->getJson('/api/tools', ['Authorization' => 'Bearer '.$fleet])->assertOk();
});

it('scopes fleet calls to the resolved agent', function () {
    $fleet = $this->owner->rotateMcpKey();
    $other = Agent::factory()->withKey('melf_otherkey')->create(['name' => 'otherbot']);

    ($this->call)('write_file', ['name' => 'otherbot', 'path' => 'o.txt', 'content' => 'x'], $fleet)->assertOk();

    expect($other->files()->where('path', 'o.txt')->exists())->toBeTrue()
        ->and($this->agent->files()->where('path', 'o.txt')->exists())->toBeFalse();
});

// ── Basic auth (URL-only clients) ───────────────────────────────────

it('accepts credentials as HTTP Basic like the MCP endpoint', function () {
    $this->withBasicAuth('whatever', 'melf_testkey')
        ->postJson('/api/tools/list_files')->assertOk();

    $fleet = $this->owner->rotateMcpKey();
    $this->withBasicAuth('grokbot', $fleet)
        ->postJson('/api/tools/list_files')->assertOk();

    $key = AgentApiKey::issue($this->agent, 'cloud', ['knowledge:read']);
    $this->withBasicAuth('x', $key)->postJson('/api/tools/knowledge_lookup', ['query' => 'x'])->assertOk();
    $this->withBasicAuth('x', $key)->postJson('/api/tools/list_files')->assertForbidden();
});

it('fails a dedicated-key call whose name claim is a different agent', function () {
    Agent::factory()->withKey('melf_otherkey')->create(['name' => 'otherbot']);

    ($this->call)('list_files', ['name' => 'otherbot'])->assertUnauthorized();

    // The same name as the key's agent passes.
    ($this->call)('list_files', ['name' => 'grokbot'])->assertOk();
});
