<?php

use App\Models\Agent;
use App\Models\Instruction;
use App\Models\User;
use App\Models\VfsFile;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->create();
});

it('renders the four console tabs', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.index'))
        ->assertOk()
        ->assertSee(route('console.agents.index'))
        ->assertSee(route('console.files.index'))
        ->assertSee(route('console.shared.index'))
        ->assertSee(route('console.instructions.index'));
});

it('redirects the dashboard to the agents tab', function () {
    $this->actingAs($this->owner)
        ->get(route('console.dashboard'))
        ->assertRedirect(route('console.agents.index'));
});

it('lists agents with file counts on the files tab', function () {
    VfsFile::writeFor($this->agent, 'a.txt', '1');
    VfsFile::writeFor($this->agent, 'b.txt', '2');

    $this->actingAs($this->owner)
        ->get(route('console.files.index'))
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

it('shows the instruction editor landing', function () {
    Instruction::factory()->for($this->agent)->create(['content' => 'rules']);

    $this->actingAs($this->owner)
        ->get(route('console.instructions.index'))
        ->assertOk()
        ->assertSee($this->agent->name)
        ->assertSee('set')
        ->assertSee(route('console.agents.instructions.edit', $this->agent));
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
