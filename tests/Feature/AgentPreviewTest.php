<?php

use App\Mcp\Tools\ReadInstructions;
use App\Mcp\Tools\ReadPersona;
use App\Models\Agent;
use App\Models\Instruction;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->create(['name' => 'nav-brain']);
});

it('shows the composed instruction payload the agent receives', function () {
    Instruction::factory()->for($this->agent)->create([
        'content' => 'You are a navigator-brain.',
        'layers' => ['Be terse.', 'Never lie.'],
    ]);
    $this->owner->update(['operator_persona' => 'Antoni, the operator.']);

    $payload = ReadInstructions::payload($this->agent);

    $this->actingAs($this->owner)
        ->get(route('console.agents.preview', $this->agent))
        ->assertOk()
        ->assertSee('You are a navigator-brain.')
        ->assertSee('Be terse.')
        ->assertSee('Antoni, the operator.');

    expect($payload['content'])->toBe('You are a navigator-brain.')
        ->and($payload['layers'])->toBe(['Be terse.', 'Never lie.'])
        ->and($payload['operator'])->toBe('Antoni, the operator.');
});

it('shows the persona payload, server instructions, and tool surface', function () {
    $this->agent->update([
        'persona' => 'gruff, precise',
        'memories' => [['at' => '2026-01-01T00:00:00Z', 'text' => 'owner prefers terse replies']],
    ]);

    $payload = ReadPersona::payload($this->agent);

    $this->actingAs($this->owner)
        ->get(route('console.agents.preview', $this->agent))
        ->assertOk()
        ->assertSee('gruff, precise')
        ->assertSee('owner prefers terse replies')
        ->assertSee('You are connected to your own private Melfina brain')
        ->assertSee('You are nav-brain')
        ->assertSee('read_instructions')
        ->assertSee('knowledge_lookup');

    expect($payload['name'])->toBe('nav-brain')
        ->and($payload['persona'])->toBe('gruff, precise')
        ->and($payload['memories'])->toHaveCount(1);
});

it('renders empty payloads cleanly for a fresh agent', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.preview', $this->agent))
        ->assertOk()
        ->assertSee('persona_hint');
});

it('is owner-only', function () {
    $this->get(route('console.agents.preview', $this->agent))
        ->assertRedirect(route('login'));
});
