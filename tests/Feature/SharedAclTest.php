<?php

use App\Models\Agent;
use App\Models\SharedAcl;
use App\Models\SharedSpace;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->create();
});

it('lets the owner create a shared space', function () {
    $this->actingAs($this->owner)
        ->post(route('console.shared.store'), ['name' => 'drop-point'])
        ->assertRedirect();

    expect(SharedSpace::where('name', 'drop-point')->exists())->toBeTrue();
});

it('rejects duplicate space names', function () {
    SharedSpace::factory()->create(['name' => 'drop-point']);

    $this->actingAs($this->owner)
        ->post(route('console.shared.store'), ['name' => 'drop-point'])
        ->assertSessionHasErrors('name');
});

it('lets the owner grant, escalate, and revoke agent ACL', function () {
    $space = SharedSpace::factory()->create();
    $route = route('console.shared.update', $space);

    $this->actingAs($this->owner)->put($route, [
        'name' => $space->name,
        'acl' => [$this->agent->id => 'read'],
    ]);

    expect($space->fresh()->permissionFor($this->agent))->toBe('read');

    $this->actingAs($this->owner)->put($route, [
        'name' => $space->name,
        'acl' => [$this->agent->id => 'write'],
    ]);

    expect($space->fresh()->permissionFor($this->agent))->toBe('write');

    $this->actingAs($this->owner)->put($route, [
        'name' => $space->name,
        'acl' => [$this->agent->id => 'none'],
    ]);

    expect($space->fresh()->permissionFor($this->agent))->toBeNull()
        ->and(SharedAcl::count())->toBe(0);
});

it('ignores acl keys that are not real agent ids', function () {
    $space = SharedSpace::factory()->create();

    $this->actingAs($this->owner)->put(route('console.shared.update', $space), [
        'name' => $space->name,
        'acl' => [99999 => 'write'],
    ])->assertSessionHasNoErrors();

    expect(SharedAcl::count())->toBe(0);
});

it('rejects invalid permission values', function () {
    $space = SharedSpace::factory()->create();

    $this->actingAs($this->owner)->put(route('console.shared.update', $space), [
        'name' => $space->name,
        'acl' => [$this->agent->id => 'admin'],
    ])->assertSessionHasErrors('acl.'.$this->agent->id);
});

it('blocks non-owners from shared routes', function () {
    $user = User::factory()->create();
    $space = SharedSpace::factory()->create();

    $this->actingAs($user)->get(route('console.shared.index'))->assertForbidden();
    $this->actingAs($user)->post(route('console.shared.store'), ['name' => 'x'])->assertForbidden();
    $this->actingAs($user)->put(route('console.shared.update', $space), [
        'name' => $space->name, 'acl' => [],
    ])->assertForbidden();
});

it('write grant implies read', function () {
    $space = SharedSpace::factory()->create();
    SharedAcl::factory()->for($space, 'sharedSpace')->for($this->agent)->create([
        'permission' => 'write',
    ]);

    expect($space->canRead($this->agent))->toBeTrue()
        ->and($space->canWrite($this->agent))->toBeTrue();
});
