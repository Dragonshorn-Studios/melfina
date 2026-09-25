<?php

use App\Models\Agent;
use App\Models\Instruction;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->create();
});

it('shows the instruction editor for an agent without instructions yet', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.instructions.edit', $this->agent))
        ->assertOk();
});

it('creates the instruction record on first save (1:1 upsert)', function () {
    $this->actingAs($this->owner)
        ->put(route('console.agents.instructions.update', $this->agent), [
            'content' => 'You are a navigator-brain.',
            'layers' => ['Be terse.', 'Never lie.'],
        ])->assertRedirect(route('console.agents.instructions.edit', $this->agent));

    $instruction = Instruction::sole();
    expect($instruction->agent_id)->toBe($this->agent->id)
        ->and($instruction->content)->toBe('You are a navigator-brain.')
        ->and($instruction->layers)->toBe(['Be terse.', 'Never lie.']);
});

it('updates existing instructions on the same record (unique association)', function () {
    $instruction = Instruction::factory()->for($this->agent)->create(['content' => 'old']);

    $this->actingAs($this->owner)
        ->put(route('console.agents.instructions.update', $this->agent), [
            'content' => 'new',
            'layers' => [],
        ]);

    expect(Instruction::count())->toBe(1)
        ->and($instruction->refresh()->content)->toBe('new')
        ->and($instruction->layers)->toBeNull();
});

it('drops empty layers and preserves order', function () {
    $this->actingAs($this->owner)
        ->put(route('console.agents.instructions.update', $this->agent), [
            'content' => 'c',
            'layers' => ['first', '', '  ', 'second'],
        ]);

    expect(Instruction::sole()->layers)->toBe(['first', 'second']);
});

it('rejects oversized input', function () {
    $this->actingAs($this->owner)
        ->put(route('console.agents.instructions.update', $this->agent), [
            'content' => str_repeat('x', 100001),
        ])->assertSessionHasErrors('content');
});

it('forbids non-owners from viewing or mutating instructions', function () {
    $user = User::factory()->create(['is_owner' => false]);

    $this->actingAs($user)
        ->get(route('console.agents.instructions.edit', $this->agent))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('console.agents.instructions.update', $this->agent), ['content' => 'x'])
        ->assertForbidden();

    expect(Instruction::count())->toBe(0);
});

it('redirects guests to login', function () {
    $this->get(route('console.agents.instructions.edit', $this->agent))
        ->assertRedirect(route('login'));
});

it('agent model exposes its instruction 1:1', function () {
    $instruction = Instruction::factory()->for($this->agent)->create();

    expect($this->agent->refresh()->instruction->id)->toBe($instruction->id);
});
