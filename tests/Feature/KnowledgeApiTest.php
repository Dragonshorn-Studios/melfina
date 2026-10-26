<?php

use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeEntryRedirect;
use App\Models\KnowledgeIdentifier;
use App\Models\KnowledgeLink;
use App\Models\KnowledgeLinkStatus;
use App\Models\KnowledgePredicate;
use App\Models\User;
use App\Services\KnowledgeGraph;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();

    // Create test entries
    $this->yukine = KnowledgeEntry::factory()->create([
        'name' => 'Yukine', 'name_lower' => 'yukine', 'type' => 'project',
        'summary' => 'Discord bridge',
    ]);

    $this->botbox = KnowledgeEntry::factory()->create([
        'name' => 'Botbox', 'name_lower' => 'botbox', 'type' => 'service',
        'summary' => 'home server',
    ]);

    $this->melfina = KnowledgeEntry::factory()->create([
        'name' => 'Melfina', 'name_lower' => 'melfina', 'type' => 'project',
        'summary' => 'Imp brain',
    ]);

    // Create a link
    $this->link = KnowledgeLink::factory()->create([
        'from_entry_id' => $this->yukine->id,
        'to_entry_id' => $this->botbox->id,
        'predicate' => KnowledgePredicate::RunsOn,
        'confidence' => 500,
        'permanent' => false,
        'disputed' => false,
        'status' => 'active',
    ]);
});

it('returns graph data with nodes and links', function () {
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.graph'))
        ->assertOk()
        ->assertJsonStructure([
            'nodes' => [
                '*' => ['id', 'name', 'type', 'summary'],
            ],
            'links' => [
                '*' => ['id', 'from', 'to', 'predicate', 'confidence', 'permanent', 'disputed'],
            ],
        ])
        ->assertJsonCount(3, 'nodes');
});

it('returns filtered graph when focus is specified', function () {
    // With focus on Yukine and 1 hop, should return Yukine and Botbox
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.graph', ['focus' => $this->yukine->id, 'hops' => 1]))
        ->assertOk()
        ->assertJsonStructure([
            'nodes',
            'links',
        ])
        ->assertJsonFragment(['name' => 'Yukine'])
        ->assertJsonFragment(['name' => 'Botbox']);
});

it('returns a single knowledge entry', function () {
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.entries.show', ['entry' => $this->yukine->id]))
        ->assertOk()
        ->assertJsonStructure([
            'id', 'name', 'type', 'summary', 'state', 'source', 'verified', 'version', 'updated_at', 'updated_by',
        ])
        ->assertJsonFragment(['name' => 'Yukine'])
        ->assertJsonFragment(['type' => 'project']);
});

it('returns predicates list', function () {
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.predicates'))
        ->assertOk()
        ->assertJsonStructure([
            'predicates',
        ]);
});

it('returns types list', function () {
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.types'))
        ->assertOk()
        ->assertJsonStructure([
            'types',
        ]);
});

it('searches knowledge entries by query', function () {
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.entries.search', ['q' => 'yukine']))
        ->assertOk()
        ->assertJsonStructure([
            'results' => [
                '*' => ['id', 'name', 'type'],
            ],
        ])
        ->assertJsonFragment(['name' => 'Yukine']);
});

it('searches knowledge entries with limit', function () {
    // Create more entries
    for ($i = 0; $i < 5; $i++) {
        KnowledgeEntry::factory()->create([
            'name' => 'Entry'.$i, 'name_lower' => 'entry'.$i, 'type' => 'concept',
            'summary' => 'Test entry',
        ]);
    }

    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.entries.search', ['q' => 'entry', 'limit' => 3]))
        ->assertOk()
        ->assertJsonCount(3, 'results');
});

it('creates a new knowledge entry via API', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.entries.store'), [
            'name' => 'New Entry',
            'type' => 'concept',
            'summary' => 'A new concept',
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'entry' => ['id', 'name', 'type', 'summary', 'version'],
            'message',
        ])
        ->assertJsonFragment(['name' => 'New Entry']);

    // Verify it was created
    $this->assertDatabaseHas('knowledge_entries', ['name' => 'New Entry']);
});

it('validates required fields when creating entry', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.entries.store'), [
            'name' => '',
            'type' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'type']);
});

it('updates an existing knowledge entry via API', function () {
    $this->actingAs($this->owner)
        ->putJson(route('api.knowledge.entries.update', ['entry' => $this->yukine->id]), [
            'name' => 'Yukine Updated',
            'type' => 'project',
            'summary' => 'Updated Discord bridge',
            'expected_version' => $this->yukine->version,
        ])
        ->assertOk()
        ->assertJsonStructure([
            'entry' => ['id', 'name', 'type', 'summary', 'version'],
            'message',
        ])
        ->assertJsonFragment(['name' => 'Yukine Updated']);

    // Verify it was updated
    $this->assertDatabaseHas('knowledge_entries', ['name' => 'Yukine Updated']);
});

it('validates version conflict when updating entry', function () {
    $this->actingAs($this->owner)
        ->putJson(route('api.knowledge.entries.update', ['entry' => $this->yukine->id]), [
            'name' => 'Yukine Updated',
            'type' => 'project',
            'expected_version' => 999, // Wrong version
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expected_version']);
});

it('creates a new knowledge link via API', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.links.store'), [
            'from' => 'Melfina',
            'to' => 'Yukine',
            'predicate' => 'related_to',
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'link' => ['id', 'from', 'to', 'predicate', 'confidence'],
            'message',
        ]);

    // Verify it was created
    $this->assertDatabaseHas('knowledge_links', [
        'from_entry_id' => $this->melfina->id,
        'to_entry_id' => $this->yukine->id,
    ]);
});

it('validates required fields when creating link', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.links.store'), [
            'from' => '',
            'to' => '',
            'predicate' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from', 'to', 'predicate']);
});

it('deletes a knowledge entry via API', function () {
    $this->actingAs($this->owner)
        ->deleteJson(route('api.knowledge.entries.destroy', ['entry' => $this->yukine->id]))
        ->assertOk()
        ->assertJsonStructure([
            'message',
        ]);

    // Verify it was deleted
    $this->assertDatabaseMissing('knowledge_entries', ['id' => $this->yukine->id]);
});

it('deletes a knowledge link via API', function () {
    $this->actingAs($this->owner)
        ->deleteJson(route('api.knowledge.links.destroy', ['link' => $this->link->id]))
        ->assertOk()
        ->assertJsonStructure([
            'message',
        ]);

    // Verify it was deleted
    $this->assertDatabaseMissing('knowledge_links', ['id' => $this->link->id]);
});

it('mounts the knowledge api under the versioned path', function () {
    // routes/api.php is mounted with the `api` prefix already applied, so
    // the group must not repeat it — otherwise the viewer calls /api/v1/...
    // and every request 404s against /api/api/v1/...
    expect(route('api.knowledge.graph'))->toBe(url('/api/v1/knowledge/graph'))
        ->and(route('api.knowledge.entries.store'))->toBe(url('/api/v1/knowledge/entries'))
        ->and(route('api.knowledge.links.confirm', ['link' => 7]))->toBe(url('/api/v1/knowledge/links/7/confirm'));

    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->all();

    expect($uris)->not->toContain('api/api/v1/knowledge/graph');
});

it('is owner-only', function () {
    $plain = User::factory()->create();

    $this->actingAs($plain)
        ->getJson(route('api.knowledge.graph'))
        ->assertForbidden();

    $this->actingAs($plain)
        ->postJson(route('api.knowledge.entries.store'), [
            'name' => 'Sneaky',
            'type' => 'concept',
        ])
        ->assertForbidden();
});

it('rejects guests', function () {
    $this->getJson(route('api.knowledge.graph'))->assertUnauthorized();

    $this->postJson(route('api.knowledge.entries.store'), [
        'name' => 'Sneaky',
        'type' => 'concept',
    ])->assertUnauthorized();
});

it('throttles the api to 60 requests a minute', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($this->owner)
            ->getJson(route('api.knowledge.graph'))
            ->assertOk();
    }

    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.graph'))
        ->assertStatus(429);
});

it('keeps the session-authenticated api under csrf protection', function () {
    // The knowledge api authenticates with the owner session, so it runs in
    // the web middleware group and must not be exempted from CSRF. The
    // key-authenticated surfaces (api/tools, api/files, api/admin) never
    // enter that group, so they need no exemption.
    $excluded = app(PreventRequestForgery::class)->getExcludedPaths();

    expect($excluded)->not->toContain('api/*')
        ->and(collect($excluded)->contains(fn ($uri) => str_starts_with((string) $uri, 'api/')))->toBeFalse();
});

it('clamps the graph node limit', function () {
    $now = now();
    $rows = [];

    for ($i = 0; $i < 505; $i++) {
        $rows[] = [
            'name' => "Bulk {$i}",
            'name_lower' => "bulk {$i}",
            'type' => 'concept',
            'aliases' => '[]',
            'summary' => '',
            'state' => '',
            'related' => '[]',
            'updated_by' => 'owner',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    KnowledgeEntry::query()->insert($rows);

    // The service only truncates when the graph is larger than the limit,
    // so the cap has to be observable to be worth pinning.
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.graph', ['limit' => 99999]))
        ->assertOk()
        ->assertJsonCount(500, 'nodes');
});

it('clamps the graph hop depth', function () {
    $chain = collect(range(1, 8))->map(fn (int $i) => KnowledgeEntry::factory()->create([
        'name' => "Chain {$i}",
        'name_lower' => "chain {$i}",
        'type' => 'concept',
    ]));

    foreach ($chain->slice(0, -1) as $index => $entry) {
        KnowledgeLink::factory()->create([
            'from_entry_id' => $entry->id,
            'to_entry_id' => $chain[$index + 1]->id,
            'predicate' => KnowledgePredicate::RelatedTo,
            'status' => 'active',
        ]);
    }

    // Eleven entries in total and a limit of ten: the focus path is what
    // applies the hop depth.
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.graph', [
            'focus' => $chain->first()->id,
            'hops' => 9999,
            'limit' => 10,
        ]))
        ->assertOk()
        ->assertJsonCount(6, 'nodes');
});

it('clamps the search page size', function () {
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.entries.search', ['q' => 'a', 'limit' => 5000]))
        ->assertOk()
        ->assertJsonPath('limit', 100);

    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.entries.search', ['q' => 'a', 'page' => 0]))
        ->assertOk()
        ->assertJsonPath('page', 1);
});

it('matches like wildcards in a search query literally', function () {
    KnowledgeEntry::factory()->create([
        'name' => 'Uptime 100%',
        'name_lower' => 'uptime 100%',
        'type' => 'concept',
    ]);

    // A bare wildcard must not turn into "match everything".
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.entries.search', ['q' => '%']))
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.name', 'Uptime 100%');
});

it('refuses to create an entry under the operator token', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.entries.store'), [
            'name' => 'operator',
            'type' => 'person',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'name' => 'That name is reserved for the installation operator.',
        ]);
});

it('does not overwrite the bound operator when creating an entry', function () {
    $operator = KnowledgeEntry::factory()->create([
        'name' => 'Antoni',
        'name_lower' => 'antoni',
        'type' => 'person',
        'summary' => 'The owner',
    ]);

    $this->owner->update(['operator_entry_id' => $operator->id]);

    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.entries.store'), [
            'name' => 'operator',
            'type' => 'project',
            'summary' => 'clobber attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    // The bound operator entry must come out untouched.
    expect($operator->fresh()->type)->toBe('person')
        ->and($operator->fresh()->summary)->toBe('The owner')
        ->and($operator->fresh()->version)->toBe(1);
});

it('refuses to create an entry whose name resolves to an existing alias', function () {
    KnowledgeIdentifier::factory()->create([
        'entry_id' => $this->yukine->id,
        'value' => 'Yuki',
        'normalized_value' => 'yuki',
    ]);

    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.entries.store'), [
            'name' => 'Yuki',
            'type' => 'person',
            'summary' => 'clobber attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    // The aliased entry keeps its own content.
    expect($this->yukine->fresh()->type)->toBe('project')
        ->and($this->yukine->fresh()->summary)->toBe('Discord bridge');
});

it('confirms a link', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.links.confirm', ['link' => $this->link->id]))
        ->assertOk()
        ->assertJsonPath('link.id', $this->link->id);

    expect($this->link->fresh()->confidence)->toBeGreaterThanOrEqual(950);
});

it('locks and unlocks a link', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.links.permanent', ['link' => $this->link->id]), [
            'permanent' => true,
        ])
        ->assertOk()
        ->assertJsonPath('link.permanent', true);

    expect($this->link->fresh()->permanent)->toBeTrue();

    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.links.permanent', ['link' => $this->link->id]), [
            'permanent' => false,
        ])
        ->assertOk()
        ->assertJsonPath('link.permanent', false);

    expect($this->link->fresh()->permanent)->toBeFalse();
});

it('retracts a link', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.links.retract', ['link' => $this->link->id]))
        ->assertOk()
        ->assertJsonPath('link.status', 'retracted');

    expect($this->link->fresh()->status)->toBe(KnowledgeLinkStatus::Retracted);
});

it('reports a retract the graph refused', function () {
    // A second entry carrying the same name makes the retract ambiguous,
    // and the graph refuses it.
    KnowledgeIdentifier::factory()->create([
        'entry_id' => $this->melfina->id,
        'value' => 'Botbox',
        'normalized_value' => 'botbox',
    ]);

    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.links.retract', ['link' => $this->link->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('link');

    // The link is untouched — the API must not report a retract that did
    // not happen.
    expect($this->link->fresh()->status)->toBe(KnowledgeLinkStatus::Active);
});

it('returns a single link', function () {
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.links.show', ['link' => $this->link->id]))
        ->assertOk()
        ->assertJsonStructure([
            'id', 'from', 'from_id', 'to', 'to_id', 'predicate', 'confidence', 'permanent', 'disputed', 'status',
        ])
        ->assertJsonPath('from_id', $this->yukine->id)
        ->assertJsonPath('to_id', $this->botbox->id)
        ->assertJsonPath('id', $this->link->id);
});

it('reports the real state after fixing a link', function () {
    $this->actingAs($this->owner)
        ->putJson(route('api.knowledge.links.update', ['link' => $this->link->id]), [
            'from' => 'Melfina',
            'to' => 'Botbox',
            'predicate' => 'runs_on',
        ])
        ->assertOk()
        ->assertJsonPath('link.from', 'Melfina')
        ->assertJsonPath('link.to', 'Botbox');

    expect($this->link->fresh()->from_entry_id)->toBe($this->melfina->id);
});

it('does not let a fix certify a link or revive a retracted one', function () {
    // A link an agent asserted keeps the confidence it had: fixing the
    // endpoints is not an owner attestation.
    app(KnowledgeGraph::class)->connect(
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
        ->putJson(route('api.knowledge.links.update', ['link' => $asserted->id]), [
            'from' => 'Melfina',
            'to' => 'Botbox',
            'predicate' => 'related_to',
        ])
        ->assertOk()
        ->assertJsonPath('link.confidence', 220)
        ->assertJsonPath('link.status', 'active');

    // A retracted link stays retracted until the owner confirms it again.
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.links.retract', ['link' => $asserted->id]))
        ->assertOk()
        ->assertJsonPath('link.status', 'retracted');

    $this->actingAs($this->owner)
        ->putJson(route('api.knowledge.links.update', ['link' => $asserted->id]), [
            'from' => 'Yukine',
            'to' => 'Botbox',
            'predicate' => 'related_to',
        ])
        ->assertOk()
        ->assertJsonPath('link.status', 'retracted');

    expect($asserted->fresh()->confidence)->toBe(0);
});

it('refuses to delete an entry that is operator-bound', function () {
    $this->owner->update(['operator_entry_id' => $this->melfina->id]);

    $this->actingAs($this->owner)
        ->deleteJson(route('api.knowledge.entries.destroy', ['entry' => $this->melfina->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('delete');

    expect(KnowledgeEntry::query()->find($this->melfina->id))->not->toBeNull();
});

it('refuses to delete an entry that is part of a redirect', function () {
    KnowledgeEntryRedirect::factory()->create([
        'from_entry_id' => $this->yukine->id,
        'to_entry_id' => $this->melfina->id,
        'author' => 'owner',
    ]);

    $this->actingAs($this->owner)
        ->deleteJson(route('api.knowledge.entries.destroy', ['entry' => $this->yukine->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('delete');

    expect(KnowledgeEntry::query()->find($this->yukine->id))->not->toBeNull();
});

it('deletes an entry with its links and observations', function () {
    $this->actingAs($this->owner)
        ->deleteJson(route('api.knowledge.entries.destroy', ['entry' => $this->yukine->id]))
        ->assertOk();

    expect(KnowledgeEntry::query()->find($this->yukine->id))->toBeNull()
        ->and(KnowledgeLink::query()->where('from_entry_id', $this->yukine->id)->count())->toBe(0);
});

it('reports whether an entry is verified', function () {
    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.entries.show', ['entry' => $this->yukine->id]))
        ->assertOk()
        ->assertJsonPath('verified', false);

    $this->yukine->update(['verified_at' => now()]);

    $this->actingAs($this->owner)
        ->getJson(route('api.knowledge.entries.show', ['entry' => $this->yukine->id]))
        ->assertOk()
        ->assertJsonPath('verified', true);
});

it('verifies an entry on update and never un-verifies it', function () {
    $this->actingAs($this->owner)
        ->putJson(route('api.knowledge.entries.update', ['entry' => $this->yukine->id]), [
            'name' => 'Yukine',
            'type' => 'project',
            'verified' => true,
        ])
        ->assertOk();

    expect($this->yukine->fresh()->verified_at)->not->toBeNull();

    // A later edit that says nothing about verification keeps the mark.
    $this->actingAs($this->owner)
        ->putJson(route('api.knowledge.entries.update', ['entry' => $this->yukine->id]), [
            'name' => 'Yukine',
            'type' => 'project',
            'summary' => 'Still the Discord bridge',
        ])
        ->assertOk();

    expect($this->yukine->fresh()->verified_at)->not->toBeNull();
});

it('reports the related names that did not resolve', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.entries.store'), [
            'name' => 'Bridge',
            'type' => 'concept',
            'related' => "Botbox\nGhost",
        ])
        ->assertCreated()
        ->assertJsonPath('unresolved_related', ['Ghost'])
        ->assertJsonFragment(['message' => 'Entry created, but these related names did not resolve: Ghost.']);
});

it('keeps the legacy related column when the field is left out', function () {
    $this->yukine->update(['related' => ['Botbox']]);

    $this->actingAs($this->owner)
        ->putJson(route('api.knowledge.entries.update', ['entry' => $this->yukine->id]), [
            'name' => 'Yukine',
            'type' => 'project',
            'summary' => 'Still the Discord bridge',
        ])
        ->assertOk();

    expect($this->yukine->fresh()->related)->toBe(['Botbox']);
});

it('maps related names onto related_to links', function () {
    $this->actingAs($this->owner)
        ->postJson(route('api.knowledge.entries.store'), [
            'name' => 'Bridge',
            'type' => 'concept',
            'related' => "Botbox\nMelfina",
        ])
        ->assertCreated();

    $bridge = KnowledgeEntry::query()->where('name_lower', 'bridge')->sole();

    expect(KnowledgeLink::query()
        ->where('from_entry_id', $bridge->id)
        ->where('predicate', KnowledgePredicate::RelatedTo->value)
        ->count())->toBe(2);
});
