<?php

use App\Models\Agent;
use App\Models\AgentApiKey;
use App\Models\AgentEnrollKey;
use App\Models\AgentEnrollment;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->enrollKey = AgentEnrollKey::issue($this->owner, 'minimax');
});

// ── POST /api/enroll ────────────────────────────────────────────────

it('rejects a missing or bogus secret with a bare 401', function () {
    $this->postJson('/api/enroll', ['id' => 'sess-1', 'name' => 'grokbot'])
        ->assertUnauthorized()
        ->assertJson(['ok' => false, 'error' => 'unauthorized']);

    $this->postJson('/api/enroll', ['secret' => 'melfe_wrong', 'id' => 'sess-1', 'name' => 'grokbot'])
        ->assertUnauthorized();

    // A revoked enroll key is the same nothing.
    AgentEnrollKey::query()->sole()->revoke();
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1', 'name' => 'grokbot'])
        ->assertUnauthorized();

    expect(AgentEnrollment::count())->toBe(0);
});

it('lands a pending report on the accept list', function () {
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1', 'name' => 'grokbot'])
        ->assertStatus(202)
        ->assertJson(['ok' => true, 'status' => 'pending']);

    $enrollment = AgentEnrollment::query()->sole();
    expect($enrollment->remote_id)->toBe('sess-1')
        ->and($enrollment->name)->toBe('grokbot')
        ->and($enrollment->isPending())->toBeTrue();

    $this->actingAs($this->owner)
        ->get(route('console.agents.index'))
        ->assertOk()
        ->assertSee('sess-1')
        ->assertSee('grokbot');
});

it('de-dupes repeat reports by remote_id and carries the latest name', function () {
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1', 'name' => 'grokbot']);
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1', 'name' => 'grokbot-renamed']);

    expect(AgentEnrollment::count())->toBe(1)
        ->and(AgentEnrollment::query()->sole()->name)->toBe('grokbot-renamed');
});

it('validates id and name only after the secret checks out', function () {
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'bad id!', 'name' => 'x'])
        ->assertJsonValidationErrorFor('id');
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1'])
        ->assertJsonValidationErrorFor('name');
});

// ── accept → minted key ─────────────────────────────────────────────

it('mints a 24h scoped key once accepted, fresh on every call', function () {
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1', 'name' => 'grokbot']);

    $this->actingAs($this->owner)
        ->post(route('console.enrollments.accept', AgentEnrollment::sole()), [
            'name' => 'grokbot',
            'scopes' => ['knowledge:read'],
        ])
        ->assertRedirect(route('console.agents.index'));

    $enrollment = AgentEnrollment::sole();
    expect($enrollment->isAccepted())->toBeTrue()
        ->and($enrollment->agent->name)->toBe('grokbot')
        ->and($enrollment->scopes)->toBe(['knowledge:read'])
        ->and($enrollment->agent->key_hash)->toBeNull(); // minted credentials only

    $response = $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1', 'name' => 'grokbot'])
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'accepted', 'agent' => 'grokbot']);

    $plaintext = $response->json('key');
    expect($plaintext)->toStartWith('melfs_');

    $key = AgentApiKey::query()->sole();
    expect($key->enrollment_id)->toBe($enrollment->id)
        ->and($key->expires_at)->not->toBeNull()
        ->and($key->expires_at->diffInSeconds(now()))->toBeLessThan(AgentApiKey::MINTED_TTL_SECONDS + 5)
        // only the hash persists — minted plaintext is never stored
        ->and($key->key_hash)->toBe(Agent::hashKey($plaintext));

    // The minted key authenticates the HTTP fallback inside its scopes…
    $this->postJson('/api/tools/knowledge_lookup', ['query' => 'x'], [
        'Authorization' => 'Bearer '.$plaintext,
    ])->assertOk()->assertJsonPath('result.found', false);

    // …and nothing outside them.
    $this->postJson('/api/tools/read_persona', [], [
        'Authorization' => 'Bearer '.$plaintext,
    ])->assertForbidden();

    // A second accepted call mints a second key — plaintext can't be re-shown.
    $again = $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1', 'name' => 'grokbot'])->json('key');
    expect($again)->not->toBe($plaintext)
        ->and(AgentApiKey::count())->toBe(2);
});

it('expires minted keys fail closed', function () {
    $agent = Agent::factory()->create(['name' => 'grokbot']);
    $enrollment = AgentEnrollment::create([
        'remote_id' => 'sess-1', 'name' => 'grokbot',
        'status' => AgentEnrollment::STATUS_ACCEPTED,
        'agent_id' => $agent->id, 'scopes' => ['knowledge:read'],
    ]);

    $plaintext = AgentApiKey::issueMinted($agent, 'enroll:sess-1', ['knowledge:read'], $enrollment);

    $this->travel(AgentApiKey::MINTED_TTL_SECONDS + 60)->seconds();

    expect(AgentApiKey::forKey($plaintext))->toBeNull()
        ->and(AgentApiKey::query()->sole()->isLive())->toBeFalse();
});

it('binds an accepted report to an existing agent of the same name', function () {
    $agent = Agent::factory()->withKey()->create(['name' => 'grokbot']);

    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1', 'name' => 'grokbot']);
    $this->actingAs($this->owner)
        ->post(route('console.enrollments.accept', AgentEnrollment::sole()), [
            'name' => 'grokbot',
            'scopes' => ['all:read'],
        ])
        ->assertSessionHasNoErrors();

    expect(Agent::count())->toBe(1)
        ->and(AgentEnrollment::sole()->agent_id)->toBe($agent->id);
});

it('refuses to accept twice, and declines a pending report', function () {
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1', 'name' => 'grokbot']);
    $enrollment = AgentEnrollment::sole();

    $this->actingAs($this->owner)
        ->post(route('console.enrollments.accept', $enrollment), [
            'name' => 'grokbot', 'scopes' => ['all:read'],
        ]);

    // Second accept 422s — decided enrollments are frozen.
    $this->actingAs($this->owner)
        ->post(route('console.enrollments.accept', $enrollment), [
            'name' => 'other', 'scopes' => ['all:read'],
        ])
        ->assertSessionHasErrors('enrollment');

    // Decline a pending report — it never mints.
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-2', 'name' => 'other']);
    $pending = AgentEnrollment::where('remote_id', 'sess-2')->sole();

    $this->actingAs($this->owner)
        ->post(route('console.enrollments.revoke', $pending))
        ->assertRedirect();

    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-2', 'name' => 'other'])
        ->assertForbidden()
        ->assertJson(['ok' => false, 'status' => 'revoked']);
});

it('revoking acceptance kills every key the enrollment minted', function () {
    $agent = Agent::factory()->create(['name' => 'grokbot']);
    $enrollment = AgentEnrollment::create([
        'remote_id' => 'sess-1', 'name' => 'grokbot',
        'status' => AgentEnrollment::STATUS_ACCEPTED,
        'agent_id' => $agent->id, 'scopes' => ['knowledge:read'],
    ]);
    $plaintext = AgentApiKey::issueMinted($agent, 'enroll:sess-1', ['knowledge:read'], $enrollment);

    $this->actingAs($this->owner)
        ->post(route('console.enrollments.revoke', $enrollment))
        ->assertRedirect();

    expect($enrollment->fresh()->isRevoked())->toBeTrue()
        ->and(AgentApiKey::forKey($plaintext))->toBeNull();

    // …and the endpoint itself stops minting.
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-1', 'name' => 'grokbot'])
        ->assertForbidden();
});

// ── console: enroll keys ────────────────────────────────────────────

it('issues and revokes enroll keys from the console, rolling same-name keys', function () {
    $first = AgentEnrollKey::query()->where('name', 'minimax')->sole();

    // Re-issuing the same name rolls it.
    $response = $this->actingAs($this->owner)
        ->post(route('console.enroll-keys.store'), ['enroll_name' => 'minimax'])
        ->assertRedirect(route('console.dashboard'));

    $plaintext = session('plaintext_enroll_key');
    expect($plaintext)->toStartWith('melfe_')
        ->and($first->fresh()->isLive())->toBeFalse()
        ->and(AgentEnrollKey::where('name', 'minimax')->whereNull('revoked_at')->sole()->key_hash)
        ->toBe(Agent::hashKey($plaintext));

    // The new key authenticates; the rolled one doesn't.
    $this->postJson('/api/enroll', ['secret' => $plaintext, 'id' => 'sess-1', 'name' => 'x'])->assertStatus(202);
    $this->postJson('/api/enroll', ['secret' => $this->enrollKey, 'id' => 'sess-2', 'name' => 'x'])->assertUnauthorized();

    // Revoke drops the live key.
    $live = AgentEnrollKey::where('name', 'minimax')->whereNull('revoked_at')->sole();
    $this->actingAs($this->owner)
        ->delete(route('console.enroll-keys.destroy', $live))
        ->assertRedirect(route('console.dashboard'));

    expect($live->fresh()->isLive())->toBeFalse();
});

it('is owner-only on the console and the index', function () {
    $plain = User::factory()->create();
    $enrollment = AgentEnrollment::create(['remote_id' => 'sess-1', 'name' => 'x']);

    $this->actingAs($plain)
        ->get(route('console.agents.index'))->assertForbidden();
    $this->actingAs($plain)
        ->post(route('console.enroll-keys.store'), ['enroll_name' => 'x'])->assertForbidden();
    $this->actingAs($plain)
        ->post(route('console.enrollments.accept', $enrollment), [
            'name' => 'x', 'scopes' => ['all:read'],
        ])->assertForbidden();
});
