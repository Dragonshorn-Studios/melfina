<?php

use App\Models\KnowledgeEntry;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
});

it('lists entries and filters by query tokens and type', function () {
    KnowledgeEntry::create([
        'name' => 'ImpPost', 'name_lower' => 'imppost', 'type' => 'concept',
        'summary' => 'directed messages between agents', 'state' => '', 'updated_by' => 'seed', 'aliases' => ['posts'], 'related' => [],
    ]);
    KnowledgeEntry::create([
        'name' => 'Fleet key', 'name_lower' => 'fleet key', 'type' => 'convention',
        'summary' => 'shared MCP credential', 'state' => '', 'updated_by' => 'seed', 'aliases' => [], 'related' => [],
    ]);

    $this->actingAs($this->owner)
        ->get(route('console.knowledge.index'))
        ->assertOk()
        ->assertSee('ImpPost')
        ->assertSee('Fleet key');

    // Token search hits aliases too; every token must match.
    $this->actingAs($this->owner)
        ->get(route('console.knowledge.index', ['q' => 'posts']))
        ->assertSee('ImpPost')
        ->assertDontSee('Fleet key');

    $this->actingAs($this->owner)
        ->get(route('console.knowledge.index', ['type' => 'convention']))
        ->assertDontSee('ImpPost')
        ->assertSee('Fleet key');
});

it('creates an entry with a recorded owner version', function () {
    $this->actingAs($this->owner)
        ->post(route('console.knowledge.store'), [
            'name' => 'ImpPost',
            'type' => 'concept',
            'summary' => 'directed messages',
            'state' => 'no broadcast, no channels',
            'aliases' => "posts\nposts\n imp-post ",
            'related' => 'agents',
            'source' => 'docs',
        ])
        ->assertRedirect();

    $entry = KnowledgeEntry::query()->sole();
    expect($entry->name)->toBe('ImpPost')
        ->and($entry->name_lower)->toBe('imppost')
        ->and($entry->aliases)->toBe(['posts', 'imp-post'])
        ->and($entry->related)->toBe(['agents'])
        ->and($entry->updated_by)->toBe('owner')
        ->and($entry->version)->toBe(1)
        ->and($entry->versions()->sole()->author)->toBe('owner');
});

it('rejects a case-insensitive name collision', function () {
    KnowledgeEntry::create([
        'name' => 'ImpPost', 'name_lower' => 'imppost', 'type' => 'concept',
        'summary' => 'x', 'state' => '', 'updated_by' => 'seed', 'aliases' => [], 'related' => [],
    ]);

    $this->actingAs($this->owner)
        ->post(route('console.knowledge.store'), [
            'name' => 'imppost', 'type' => 'concept',
        ])
        ->assertSessionHasErrors('name');

    expect(KnowledgeEntry::count())->toBe(1);
});

it('updates an entry and records a new version', function () {
    $entry = KnowledgeEntry::create([
        'name' => 'ImpPost', 'name_lower' => 'imppost', 'type' => 'concept',
        'summary' => 'x', 'state' => '', 'updated_by' => 'seed', 'aliases' => [], 'related' => [], 'version' => 1,
    ]);
    $entry->recordVersion('grokbot');

    $this->actingAs($this->owner)
        ->put(route('console.knowledge.update', $entry), [
            'name' => 'ImpPost', 'type' => 'concept',
            'summary' => 'directed messages between agents',
            'expected_version' => 1,
            'verified' => '1',
        ])
        ->assertRedirect(route('console.knowledge.edit', $entry));

    $entry->refresh();
    expect($entry->summary)->toBe('directed messages between agents')
        ->and($entry->version)->toBe(2)
        ->and($entry->verified_at)->not->toBeNull()
        ->and($entry->versions()->count())->toBe(2)
        ->and($entry->versions()->latest('version')->first()->author)->toBe('owner');
});

it('fails a stale edit on version conflict instead of clobbering', function () {
    $entry = KnowledgeEntry::create([
        'name' => 'ImpPost', 'name_lower' => 'imppost', 'type' => 'concept',
        'summary' => 'x', 'state' => '', 'updated_by' => 'seed', 'aliases' => [], 'related' => [], 'version' => 3,
    ]);

    $this->actingAs($this->owner)
        ->put(route('console.knowledge.update', $entry), [
            'name' => 'ImpPost', 'type' => 'concept',
            'summary' => 'clobber',
            'expected_version' => 1,
        ])
        ->assertSessionHasErrors('expected_version');

    expect($entry->fresh()->summary)->toBe('x')
        ->and($entry->fresh()->version)->toBe(3);
});

it('shows version history and deletes an entry with its history', function () {
    $entry = KnowledgeEntry::create([
        'name' => 'ImpPost', 'name_lower' => 'imppost', 'type' => 'concept',
        'summary' => 'x', 'state' => '', 'updated_by' => 'seed', 'aliases' => [], 'related' => [], 'version' => 1,
    ]);
    $entry->recordVersion('grokbot');

    $this->actingAs($this->owner)
        ->get(route('console.knowledge.edit', $entry))
        ->assertOk()
        ->assertSee('VERSION HISTORY')
        ->assertSee('grokbot');

    $this->actingAs($this->owner)
        ->delete(route('console.knowledge.destroy', $entry))
        ->assertRedirect(route('console.knowledge.index'));

    expect(KnowledgeEntry::count())->toBe(0)
        ->and($entry->versions()->count())->toBe(0); // cascaded
});

it('is owner-only', function () {
    $plain = User::factory()->create();
    $entry = KnowledgeEntry::create([
        'name' => 'x', 'name_lower' => 'x', 'type' => 'concept',
        'summary' => '', 'state' => '', 'updated_by' => 'seed', 'aliases' => [], 'related' => [],
    ]);

    $this->actingAs($plain)->get(route('console.knowledge.index'))->assertForbidden();
    $this->actingAs($plain)->post(route('console.knowledge.store'), ['name' => 'y', 'type' => 'concept'])->assertForbidden();
    $this->actingAs($plain)->put(route('console.knowledge.update', $entry), ['name' => 'x', 'type' => 'concept'])->assertForbidden();
    $this->actingAs($plain)->delete(route('console.knowledge.destroy', $entry))->assertForbidden();
});
