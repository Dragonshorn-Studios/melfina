<?php

use App\Models\Agent;
use App\Models\Instruction;
use App\Models\SharedSpace;
use App\Models\User;
use App\Models\VfsFile;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->create();
});

it('renders the sidebar with agents and section links', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.index'))
        ->assertOk()
        ->assertSee($this->agent->name)
        ->assertSee(route('console.files.show', $this->agent))
        ->assertSee(route('console.shared.index'))
        ->assertSee(route('console.clients.index'))
        ->assertSee(route('console.agents.create'));
});

it('folds the global files and instructions indexes into the agent list', function () {
    $this->actingAs($this->owner)
        ->get(route('console.files.index'))
        ->assertRedirect(route('console.agents.index'));

    $this->actingAs($this->owner)
        ->get(route('console.instructions.index'))
        ->assertRedirect(route('console.agents.index'));
});

it('renders the per-agent tab strip on agent pages', function () {
    $this->actingAs($this->owner)
        ->get(route('console.files.show', $this->agent))
        ->assertOk()
        ->assertSee(route('console.files.show', $this->agent))
        ->assertSee(route('console.agents.instructions.edit', $this->agent))
        ->assertSee(route('console.agents.show', $this->agent))
        ->assertSee(route('console.agents.connect', $this->agent));
});

it('renders the connect guides for an agent', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.connect', $this->agent))
        ->assertOk()
        ->assertSee(url('/mcp'))
        ->assertSee('claude mcp add --transport http melfina', false)
        ->assertSee('mcp-remote')
        ->assertSee('MCP_REDIRECT_DOMAINS')
        ->assertSee('~/.mavis/mcp/mcp.json')
        ->assertSee('Bearer melf_…', false);
});

it('shows fleet stats and agent rows on the dashboard', function () {
    VfsFile::writeFor($this->agent, 'a.txt', '1');
    $this->agent->rotateKey();
    $space = SharedSpace::factory()->create();
    $space->aclEntries()->create(['agent_id' => $this->agent->id, 'permission' => 'write']);

    $this->actingAs($this->owner)
        ->get(route('console.dashboard'))
        ->assertOk()
        ->assertSee('Fleet at a glance')
        ->assertSee($this->agent->name)
        ->assertSee($space->name)
        ->assertSee(route('console.files.show', $this->agent))
        ->assertSee(route('console.shared.edit', $space))
        ->assertSee(route('console.agents.create'))
        ->assertSee('issued', false);
});

it('shows agent file counts in the sidebar', function () {
    VfsFile::writeFor($this->agent, 'a.txt', '1');
    VfsFile::writeFor($this->agent, 'b.txt', '2');

    $this->actingAs($this->owner)
        ->get(route('console.agents.index'))
        ->assertOk()
        ->assertSee($this->agent->name)
        ->assertSee('text-steel">2<', false);
});

it('lets the owner browse, write, and delete files in an agent tree', function () {
    VfsFile::writeFor($this->agent, 'notes/plan.txt', 'original');

    $this->actingAs($this->owner)
        ->get(route('console.files.show', $this->agent))
        ->assertOk()
        ->assertSee('notes/plan.txt');

    $this->actingAs($this->owner)
        ->put(route('console.files.update', $this->agent), [
            'path' => 'notes/plan.txt',
            'content' => 'edited',
        ])->assertRedirect(route('console.files.show', $this->agent));

    expect(VfsFile::readFor($this->agent, 'notes/plan.txt'))->toBe('edited');

    $this->actingAs($this->owner)
        ->put(route('console.files.update', $this->agent), [
            'path' => 'fresh/new.txt',
            'content' => 'seeded',
        ]);

    expect(VfsFile::readFor($this->agent, 'fresh/new.txt'))->toBe('seeded');

    $this->actingAs($this->owner)
        ->delete(route('console.files.destroy', $this->agent), [
            'path' => 'fresh/new.txt',
        ]);

    expect(VfsFile::readFor($this->agent, 'fresh/new.txt'))->toBeNull();
});

it('rejects invalid paths on file write', function () {
    $this->actingAs($this->owner)
        ->put(route('console.files.update', $this->agent), [
            'path' => '../escape.txt',
            'content' => 'x',
        ])->assertSessionHasErrors('path');
});

it('shows the per-agent instruction editor', function () {
    Instruction::factory()->for($this->agent)->create(['content' => 'rules']);

    $this->actingAs($this->owner)
        ->get(route('console.agents.instructions.edit', $this->agent))
        ->assertOk()
        ->assertSee('rules');
});

it('renders the dismiss control on a flashed key', function () {
    $this->actingAs($this->owner)
        ->withSession(['plaintext_key' => 'melf_secret'])
        ->get(route('console.agents.show', $this->agent))
        ->assertOk()
        ->assertSee('melf_secret')
        ->assertSee('dismiss');
});

it('blocks non-owners from files and instructions routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('console.files.index'))->assertForbidden();
    $this->actingAs($user)->get(route('console.files.show', $this->agent))->assertForbidden();
    $this->actingAs($user)->put(route('console.files.update', $this->agent), [
        'path' => 'x', 'content' => 'x',
    ])->assertForbidden();
    $this->actingAs($user)->get(route('console.instructions.index'))->assertForbidden();
});
