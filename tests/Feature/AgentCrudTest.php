<?php

use App\Models\Agent;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
});

it('lets the owner list agents', function () {
    Agent::factory()->count(2)->create();

    $this->actingAs($this->owner)
        ->get(route('console.agents.index'))
        ->assertOk();
});

it('creates an agent and stores only the key hash', function () {
    $this->actingAs($this->owner)
        ->post(route('console.agents.store'), ['name' => 'nav-brain'])
        ->assertRedirect();

    $agent = Agent::sole();
    expect($agent->name)->toBe('nav-brain')
        ->and($agent->status)->toBe(Agent::STATUS_ACTIVE)
        ->and($agent->key_hash)->not->toBeNull()
        ->and($agent->key_hash)->not->toContain('melf_')
        ->and(strlen($agent->key_hash))->toBe(64);
});

it('shows the plaintext key once after create', function () {
    $response = $this->actingAs($this->owner)
        ->post(route('console.agents.store'), ['name' => 'nav-brain']);

    $response->assertSessionHas('plaintext_key', fn ($key) => str_starts_with($key, 'melf_'));
});

it('rotates the key, stores the new hash, and invalidates the old key', function () {
    $agent = Agent::factory()->withKey('melf_oldkey')->create();
    $oldHash = $agent->key_hash;

    $this->actingAs($this->owner)
        ->post(route('console.agents.rotate', $agent))
        ->assertSessionHas('plaintext_key');

    $agent->refresh();
    expect($agent->key_hash)->not->toBe($oldHash)
        ->and(Agent::forKey('melf_oldkey'))->toBeNull();
});

it('renames an agent', function () {
    $agent = Agent::factory()->create(['name' => 'old-name']);

    $this->actingAs($this->owner)
        ->put(route('console.agents.update', $agent), [
            'name' => 'new-name',
            'status' => 'active',
        ])->assertRedirect(route('console.agents.show', $agent));

    expect($agent->refresh()->name)->toBe('new-name');
});

it('disables and re-enables an agent', function () {
    $agent = Agent::factory()->withKey('melf_disabledkey')->create();

    $this->actingAs($this->owner)
        ->put(route('console.agents.update', $agent), [
            'name' => $agent->name,
            'status' => 'disabled',
        ]);

    expect($agent->refresh()->status)->toBe(Agent::STATUS_DISABLED)
        ->and(Agent::forKey('melf_disabledkey'))->toBeNull();

    $this->actingAs($this->owner)
        ->put(route('console.agents.update', $agent), [
            'name' => $agent->name,
            'status' => 'active',
        ]);

    expect($agent->refresh()->status)->toBe(Agent::STATUS_ACTIVE);
});

it('rejects invalid status values', function () {
    $agent = Agent::factory()->create();

    $this->actingAs($this->owner)
        ->put(route('console.agents.update', $agent), [
            'name' => $agent->name,
            'status' => 'bogus',
        ])->assertSessionHasErrors('status');
});

it('resolves an agent from a plaintext key and fails closed on bad keys', function () {
    $agent = Agent::factory()->withKey('melf_resolvable')->create();

    expect(Agent::forKey('melf_resolvable')?->id)->toBe($agent->id)
        ->and(Agent::forKey('melf_unknown'))->toBeNull()
        ->and(Agent::forKey('not-a-key'))->toBeNull()
        ->and(Agent::forKey(''))->toBeNull();
});

it('blocks guests and non-owners from agent routes', function () {
    $agent = Agent::factory()->create();

    $this->get(route('console.agents.index'))->assertRedirect(route('login'));

    $user = User::factory()->create(['is_owner' => false]);
    $this->actingAs($user)->get(route('console.agents.index'))->assertForbidden();
    $this->actingAs($user)->post(route('console.agents.store'), ['name' => 'x'])->assertForbidden();
});
