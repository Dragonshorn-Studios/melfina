<?php

use App\Models\Agent;
use App\Models\SharedFile;
use App\Models\SharedSpace;
use App\Models\User;
use App\Models\VfsFile;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->create(['name' => 'grokbot']);
    $this->space = SharedSpace::factory()->create(['name' => 'ops']);

    foreach (['top.txt', 'notes/plan.txt', 'notes/adr/001.md', 'scratch/loose.md'] as $path) {
        VfsFile::writeFor($this->agent, $path, 'x');
        SharedFile::writeFor($this->space, $path, 'x');
    }
});

it('browses an agent tree one directory level at a time', function () {
    // Root: only the immediate level — dirs as links, root files as rows.
    $this->actingAs($this->owner)
        ->get(route('console.files.show', $this->agent))
        ->assertOk()
        ->assertSee('notes/')
        ->assertSee('scratch/')
        ->assertSee('top.txt')
        ->assertDontSee('plan.txt', false)
        ->assertDontSee('001.md');

    // Descend into notes/ — breadcrumb trail + its own level's contents.
    $this->actingAs($this->owner)
        ->get(route('console.files.show', ['agent' => $this->agent, 'dir' => 'notes/']))
        ->assertOk()
        ->assertSee('plan.txt')
        ->assertSee('adr/')
        ->assertDontSee('top.txt')
        ->assertDontSee('loose.md');

    // Two levels down.
    $this->actingAs($this->owner)
        ->get(route('console.files.show', ['agent' => $this->agent, 'dir' => 'notes/adr/']))
        ->assertOk()
        ->assertSee('001.md');
});

it('browses a shared space the same way and deletes files', function () {
    $this->actingAs($this->owner)
        ->get(route('console.shared.show', $this->space))
        ->assertOk()
        ->assertSee('notes/')
        ->assertSee('top.txt')
        ->assertDontSee('001.md');

    $this->actingAs($this->owner)
        ->get(route('console.shared.show', ['shared' => $this->space, 'dir' => 'notes/']))
        ->assertOk()
        ->assertSee('plan.txt');

    $this->actingAs($this->owner)
        ->delete(route('console.shared.file.destroy', $this->space), [
            'path' => 'notes/plan.txt',
            'dir' => 'notes/',
        ])
        ->assertRedirect(route('console.shared.show', ['shared' => $this->space, 'dir' => 'notes/']));

    expect($this->space->files()->where('path', 'notes/plan.txt')->exists())->toBeFalse()
        // a sibling at the same level survives
        ->and($this->space->files()->where('path', 'notes/adr/001.md')->exists())->toBeTrue();
});

it('falls back to the root listing on a traversal dir', function () {
    $this->actingAs($this->owner)
        ->get(route('console.files.show', ['agent' => $this->agent, 'dir' => '../../etc']))
        ->assertOk()
        ->assertSee('top.txt');

    // A well-formed but empty dir is a normal level, not an error.
    $this->actingAs($this->owner)
        ->get(route('console.files.show', ['agent' => $this->agent, 'dir' => 'nope/']))
        ->assertOk()
        ->assertSee('Nothing at this level.');
});

it('returns to the same directory after deleting an agent file', function () {
    $this->actingAs($this->owner)
        ->delete(route('console.files.destroy', $this->agent), [
            'path' => 'notes/plan.txt',
            'dir' => 'notes/',
        ])
        ->assertRedirect(route('console.files.show', ['agent' => $this->agent, 'dir' => 'notes/']));

    expect(VfsFile::readFor($this->agent, 'notes/plan.txt'))->toBeNull();
});

it('is owner-only on shared file deletion', function () {
    $plain = User::factory()->create();

    $this->actingAs($plain)
        ->delete(route('console.shared.file.destroy', $this->space), ['path' => 'top.txt'])
        ->assertForbidden();

    expect($this->space->files()->where('path', 'top.txt')->exists())->toBeTrue();
});
