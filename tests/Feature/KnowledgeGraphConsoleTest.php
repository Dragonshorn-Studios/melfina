<?php

use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeIdentifierKind;
use App\Models\KnowledgeLink;
use App\Models\KnowledgeLinkStatus;
use App\Models\KnowledgePredicate;
use App\Models\User;
use App\Services\KnowledgeGraph;
use App\Services\KnowledgeIdentityAdmin;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->graph = app(KnowledgeGraph::class);
    $this->yukine = KnowledgeEntry::factory()->create([
        'name' => 'Yukine', 'name_lower' => 'yukine', 'type' => 'project', 'summary' => 'Discord bridge',
    ]);
    $this->botbox = KnowledgeEntry::factory()->create([
        'name' => 'Botbox', 'name_lower' => 'botbox', 'type' => 'service', 'summary' => 'home server',
    ]);
});

it('renders the constellation with entities and the inspect chrome', function () {
    $this->actingAs($this->owner)
        ->get(route('console.knowledge.graph'))
        ->assertOk()
        ->assertSee('Constellation')
        ->assertSee('Yukine')
        ->assertSee('NEW LINK')
        ->assertSee('NEW ENTITY')
        ->assertSee('mark permanent');

    $this->actingAs($this->owner)
        ->get(route('console.knowledge.index'))
        ->assertOk()
        ->assertSee(route('console.knowledge.graph', ['focus' => $this->yukine->id]), false);
});

it('lets the owner write a typed link from the graph', function () {
    $this->actingAs($this->owner)
        ->post(route('console.knowledge.links.store'), [
            'from' => 'Yukine',
            'to' => 'Botbox',
            'predicate' => 'runs_on',
            'note' => 'owner said so',
        ])
        ->assertRedirect();

    $link = KnowledgeLink::query()->sole();
    expect($link->predicate)->toBe(KnowledgePredicate::RunsOn)
        ->and($link->from_entry_id)->toBe($this->yukine->id)
        ->and($link->permanent)->toBeFalse()
        ->and($link->confidence)->toBeGreaterThanOrEqual(900);
});

it('does not spawn a node when an endpoint is missing', function () {
    $this->actingAs($this->owner)
        ->from(route('console.knowledge.graph'))
        ->post(route('console.knowledge.links.store'), [
            'from' => 'Yukine',
            'to' => 'No Such Host',
            'predicate' => 'runs_on',
        ])
        ->assertRedirect(route('console.knowledge.graph'))
        ->assertSessionHasErrors('link');

    expect(KnowledgeLink::query()->count())->toBe(0)
        ->and(KnowledgeEntry::query()->where('name_lower', 'no such host')->exists())->toBeFalse();
});

it('tells the owner when a link end is ambiguous rather than missing', function () {
    $alex = KnowledgeEntry::factory()->create([
        'name' => 'Alex', 'name_lower' => 'alex', 'type' => 'person',
    ]);
    $alexandra = KnowledgeEntry::factory()->create([
        'name' => 'Alexandra', 'name_lower' => 'alexandra', 'type' => 'person',
    ]);
    app(KnowledgeIdentityAdmin::class)->addIdentifier($alexandra, 'Alex', KnowledgeIdentifierKind::Alias);

    $this->graph->connect('grokbot', KnowledgeAuthorKind::Agent, 'Yukine', 'Botbox', KnowledgePredicate::RelatedTo);
    $link = KnowledgeLink::query()->sole();

    $this->actingAs($this->owner)
        ->from(route('console.knowledge.graph', ['focus' => $link->from_entry_id, 'link' => $link->id]))
        ->put(route('console.knowledge.links.update', $link), [
            'from' => 'Yukine',
            'to' => 'Alex',
            'predicate' => 'runs_on',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('link');

    expect(session('errors')->first('link'))->toContain('matches more than one entity')
        ->and($link->fresh()->to_entry_id)->toBe($this->botbox->id);

    expect($alex->id)->not->toBe($alexandra->id);
});

it('lets the owner confirm and then retarget a link', function () {
    $this->graph->connect('grokbot', KnowledgeAuthorKind::Agent, 'Yukine', 'Botbox', KnowledgePredicate::RelatedTo);
    $link = KnowledgeLink::query()->sole();

    $this->actingAs($this->owner)
        ->put(route('console.knowledge.links.confirm', $link))
        ->assertRedirect();

    expect($link->fresh()->confidence)->toBeGreaterThanOrEqual(950)
        ->and($link->fresh()->permanent)->toBeFalse();

    $this->actingAs($this->owner)
        ->put(route('console.knowledge.links.update', $link), [
            'from' => 'Yukine',
            'to' => 'Botbox',
            'predicate' => 'runs_on',
        ])
        ->assertRedirect();

    expect($link->fresh()->predicate)->toBe(KnowledgePredicate::RunsOn);
});

it('marks a link permanent and refuses to need a second confirm', function () {
    $this->graph->connect('grokbot', KnowledgeAuthorKind::Agent, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);
    $link = KnowledgeLink::query()->sole();

    $this->actingAs($this->owner)
        ->put(route('console.knowledge.links.permanent', $link), ['permanent' => '1'])
        ->assertRedirect();

    expect($link->fresh()->permanent)->toBeTrue()
        ->and($link->fresh()->confidence)->toBeGreaterThanOrEqual(950);
});

it('lets the owner retract a link from the graph', function () {
    $this->graph->connect('owner', KnowledgeAuthorKind::Owner, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);
    $link = KnowledgeLink::query()->sole();

    $this->actingAs($this->owner)
        ->delete(route('console.knowledge.links.destroy', $link))
        ->assertRedirect();

    expect($link->fresh()->status->value)->toBe('retracted');
});

it('creates an entity from the graph and returns to the constellation', function () {
    $response = $this->actingAs($this->owner)
        ->post(route('console.knowledge.store'), [
            'name' => 'Shiori',
            'type' => 'project',
            'summary' => 'another mind',
            'return' => 'graph',
        ]);

    $entry = KnowledgeEntry::query()->where('name_lower', 'shiori')->sole();
    expect($entry->name)->toBe('Shiori');
    $response->assertRedirect(route('console.knowledge.graph', ['focus' => $entry->id]));
});

it('is owner-only', function () {
    $plain = User::factory()->create();
    $this->graph->connect('owner', KnowledgeAuthorKind::Owner, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);
    $link = KnowledgeLink::query()->sole();

    $this->actingAs($plain)->get(route('console.knowledge.graph'))->assertForbidden();
    $this->actingAs($plain)->post(route('console.knowledge.links.store'), [
        'from' => 'Yukine', 'to' => 'Botbox', 'predicate' => 'runs_on',
    ])->assertForbidden();
    $this->actingAs($plain)->put(route('console.knowledge.links.permanent', $link), ['permanent' => '1'])->assertForbidden();
});

it('points the constellation forms at the json api', function () {
    $this->actingAs($this->owner)
        ->get(route('console.knowledge.graph'))
        ->assertOk()
        ->assertSee(route('api.knowledge.entries.store'), false)
        ->assertSee(route('api.knowledge.links.store'), false)
        ->assertSee(route('api.knowledge.links.retract', ['link' => 0]), false)
        ->assertSee('list="kg-search-results"', false);
});

it('keeps the markup the viewer reads in place', function () {
    // The viewer looks these ids up by name and rewrites the `/0`
    // placeholder in the link actions with the selected link id. A renamed
    // id silently disables a button; a changed action shape sends every
    // link action to id 0.
    $response = $this->actingAs($this->owner)
        ->get(route('console.knowledge.graph'))
        ->assertOk();

    foreach ([
        'kg-search', 'kg-search-results', 'kg-data', 'kg-refresh',
        'kg-new-entity-form', 'kg-new-link-form', 'kg-new-from',
        'kg-fix-form', 'kg-confirm-form', 'kg-permanent-form',
        'kg-unpermanent-form', 'kg-retract-form',
        'kg-inspect-empty', 'kg-inspect-node', 'kg-inspect-link',
        'kg-node-edit',
    ] as $id) {
        $response->assertSee('id="'.$id.'"', false);
    }

    // The CSRF token the viewer sends back on writes.
    $response->assertSee('name="csrf-token"', false);

    // The action templates the viewer rewrites per link.
    $response->assertSee(route('api.knowledge.links.update', ['link' => 0]), false)
        ->assertSee(route('api.knowledge.links.confirm', ['link' => 0]), false)
        ->assertSee(route('api.knowledge.links.permanent', ['link' => 0]), false)
        ->assertSee(route('api.knowledge.links.retract', ['link' => 0]), false);
});

it('does not let a console fix certify a link or revive a retracted one', function () {
    $this->graph->connect(
        'grokbot',
        KnowledgeAuthorKind::Agent,
        'Yukine',
        'Botbox',
        KnowledgePredicate::RelatedTo,
    );

    $asserted = KnowledgeLink::query()
        ->where('predicate', KnowledgePredicate::RelatedTo->value)
        ->sole();

    $this->actingAs($this->owner)
        ->put(route('console.knowledge.links.update', $asserted), [
            'from' => 'Melfina',
            'to' => 'Botbox',
            'predicate' => 'related_to',
        ])
        ->assertRedirect();

    // Fixing the endpoints is not an owner attestation.
    expect($asserted->fresh()->confidence)->toBe(220)
        ->and($asserted->fresh()->status)->toBe(KnowledgeLinkStatus::Active);

    $this->actingAs($this->owner)
        ->delete(route('console.knowledge.links.destroy', $asserted))
        ->assertRedirect();

    $this->actingAs($this->owner)
        ->put(route('console.knowledge.links.update', $asserted), [
            'from' => 'Yukine',
            'to' => 'Botbox',
            'predicate' => 'related_to',
        ])
        ->assertRedirect();

    // A retracted link stays retracted until the owner confirms it again.
    expect($asserted->fresh()->confidence)->toBe(0)
        ->and($asserted->fresh()->status)->toBe(KnowledgeLinkStatus::Retracted);
});
