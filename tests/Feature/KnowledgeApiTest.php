<?php

use App\Models\KnowledgeEntry;
use App\Models\KnowledgeLink;
use App\Models\KnowledgePredicate;
use App\Models\User;

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
