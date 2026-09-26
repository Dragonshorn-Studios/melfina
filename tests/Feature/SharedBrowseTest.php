<?php

use App\Models\SharedFile;
use App\Models\SharedSpace;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->space = SharedSpace::factory()->create(['name' => 'ops']);
    SharedFile::factory()->for($this->space)->create(['path' => 'notes/plan.txt', 'content' => 'drift west']);
});

it('lets the owner browse a space and read a file inside', function () {
    $this->actingAs($this->owner)
        ->get(route('console.shared.show', $this->space))
        ->assertOk()
        ->assertSee('ops')
        ->assertSee('notes/plan.txt');

    $this->actingAs($this->owner)
        ->get(route('console.shared.file', ['shared' => $this->space, 'path' => 'notes/plan.txt']))
        ->assertOk()
        ->assertSee('drift west');
});

it('404s on files outside the space', function () {
    $other = SharedSpace::factory()->create();
    SharedFile::factory()->for($other)->create(['path' => 'elsewhere.txt', 'content' => 'x']);

    $this->actingAs($this->owner)
        ->get(route('console.shared.file', ['shared' => $this->space, 'path' => 'elsewhere.txt']))
        ->assertNotFound();
});

it('locks browsing to the owner', function () {
    $guest = User::factory()->create(['is_owner' => false]);

    $this->actingAs($guest)
        ->get(route('console.shared.show', $this->space))
        ->assertForbidden();

    $this->actingAs($guest)
        ->get(route('console.shared.file', ['shared' => $this->space, 'path' => 'notes/plan.txt']))
        ->assertForbidden();
});

it('shows pagination, not the empty state, when the page is out of range', function () {
    $this->actingAs($this->owner)
        ->get(route('console.shared.show', $this->space).'?page=9')
        ->assertOk()
        ->assertDontSee('No files in this space yet.');
});
