<?php

use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::deleteDirectory(storage_path('app/mcp-plugins'));

    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->withKey()->create();

    $this->mcpTool = function (string $tool, array $args = [], ?string $bearer = null) {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $args],
        ], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.($bearer ?? $this->key)]);

        return $response->json('result');
    };

    $this->key = 'melf_testkey';
    $this->agent->update(['key_hash' => Agent::hashKey($this->key)]);
});

it('lets the agent maintain its persona, description, and memories', function () {
    ($this->mcpTool)('update_persona', [
        'description' => 'the ops imp',
        'persona' => 'gruff, precise, never sleeps',
    ]);
    ($this->mcpTool)('update_persona', ['remember' => 'owner prefers terse replies']);
    ($this->mcpTool)('update_persona', ['remember' => 'prod host is melfina.dragonshorn.cloud']);

    $fresh = $this->agent->fresh();
    expect($fresh->description)->toBe('the ops imp')
        ->and($fresh->persona)->toBe('gruff, precise, never sleeps')
        ->and($fresh->memories)->toHaveCount(2)
        ->and($fresh->memories[0]['text'])->toBe('owner prefers terse replies');

    $read = json_decode(mcpText(($this->mcpTool)('read_persona')), true);
    expect($read['name'])->toBe($this->agent->name)
        ->and($read['persona'])->toBe('gruff, precise, never sleeps')
        ->and($read['memories'])->toHaveCount(2);
});

it('lists other agents with their public descriptions', function () {
    Agent::factory()->create(['name' => 'kuchcik', 'description' => 'kitchen imp']);
    Agent::factory()->create(['name' => 'doczlap', 'status' => Agent::STATUS_DISABLED]);
    $this->agent->update(['description' => 'the ops imp']);

    $decoded = json_decode(mcpText(($this->mcpTool)('list_agents')), true);
    $byName = collect($decoded['agents'])->keyBy('name');

    expect($byName->keys()->sort()->values()->all())->toBe(collect([$this->agent->name, 'kuchcik'])->sort()->values()->all())
        ->and($byName['kuchcik']['description'])->toBe('kitchen imp')
        ->and($byName[$this->agent->name]['you'])->toBeTrue()
        ->and($byName['kuchcik']['you'])->toBeFalse();
});

it('appends the shared operator persona to read_instructions', function () {
    $this->owner->update(['operator_persona' => 'The user is Antoni — address them as Antoni.']);

    $decoded = json_decode(mcpText(($this->mcpTool)('read_instructions')), true);
    expect($decoded['operator'])->toBe('The user is Antoni — address them as Antoni.')
        ->and($decoded['persona_hint'])->toContain('update_persona');
});

it('lets the owner edit the operator persona and agent description from the console', function () {
    $this->actingAs($this->owner)
        ->put(route('console.mcp.operator-persona.store'), ['operator_persona' => 'Antoni, Dragonshorn lead'])
        ->assertRedirect(route('console.mcp.connect'));

    expect($this->owner->fresh()->operator_persona)->toBe('Antoni, Dragonshorn lead');

    $this->actingAs($this->owner)
        ->put(route('console.agents.update', $this->agent), [
            'name' => $this->agent->name,
            'status' => Agent::STATUS_ACTIVE,
            'description' => 'the ops imp',
        ]);

    expect($this->agent->fresh()->description)->toBe('the ops imp');

    // Non-owners can't touch either.
    $guest = User::factory()->create(['is_owner' => false]);
    $this->actingAs($guest)
        ->put(route('console.mcp.operator-persona.store'), ['operator_persona' => 'x'])
        ->assertForbidden();
    $this->actingAs($guest)
        ->put(route('console.agents.update', $this->agent), [
            'name' => $this->agent->name,
            'status' => Agent::STATUS_ACTIVE,
            'description' => 'hijack',
        ])
        ->assertForbidden();
});

it('rejects a persona update with nothing to update', function () {
    $result = ($this->mcpTool)('update_persona', []);

    expect($result['isError'])->toBeTrue();
});
