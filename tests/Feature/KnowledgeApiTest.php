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
        ->getJson('/api/knowledge/graph')
        ->assertOk()
        ->assertJsonStructure([
            'nodes' => [
                '*' => ['id', 'name', 'type', 'summary'],
            ],
            'links' => [
                '*' => ['id', 'from', 'to', 'predicate', 'confidence', 'permanent', 'disputed'],
            ],
        ])
        ->assertJsonCount('nodes', 3)
        ->assertJsonCount('links', 1);
});

it('returns filtered graph when focus is specified', function () {
    // Create another entry not connected to Yukine
    $isolated = KnowledgeEntry::factory()->create([
        'name' => 'Isolated', 'name_lower' => 'isolated', 'type' => 'concept',
        'summary' => 'Not connected',
    ]);
    
    $this->actingAs($this->owner)
        ->getJson('/api/knowledge/graph?focus='.$this->yukine->id.'&hops=1')
        ->assertOk()
        ->assertJsonStructure([
            'nodes',
            'links',
        ])
        ->assertJsonFragment(['name' => 'Yukine'])
        ->assertJsonFragment(['name' => 'Botbox'])
        ->assertJsonMissing(['name' => 'Isolated']);
});

it('returns a single knowledge entry', function () {
    $this->actingAs($this->owner)
        ->getJson('/api/knowledge/entries/'.$this->yukine->id)
        ->assertOk()
        ->assertJsonStructure([
            'id', 'name', 'type', 'summary', 'state', 'source', 'verified', 'version', 'updated_at', 'updated_by',
        ])
        ->assertJsonFragment(['name' => 'Yukine'])
        ->assertJsonFragment(['type' => 'project']);
});

it('returns predicates list', function () {
    $this->actingAs($this->owner)
        ->getJson('/api/knowledge/predicates')
        ->assertOk()
        ->assertJsonStructure([
            'predicates' => [
                '*' => ['value', 'label'],
            ],
        ]);
});

it('returns types list', function () {
    $this->actingAs($this->owner)
        ->getJson('/api/knowledge/types')
        ->assertOk()
        ->assertJsonStructure([
            'types' => [
                '*' => ['value', 'label'],
            ],
        ]);
});

it('searches knowledge entries by query', function () {
    $this->actingAs($this->owner)
        ->getJson('/api/knowledge/entries?q=yukine')
        ->assertOk()
        ->assertJsonStructure([
            'results' => [
                '*' => ['id', 'name', 'type', 'summary'],
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
        ->getJson('/api/knowledge/entries?q=entry&limit=3')
        ->assertOk()
        ->assertJsonCount('results', 3);
});

it('creates a new knowledge entry via API', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/knowledge/entries', [
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
        ->postJson('/api/knowledge/entries', [
            'name' => '',
            'type' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'type']);
});

it('updates an existing knowledge entry via API', function () {
    $this->actingAs($this->owner)
        ->putJson('/api/knowledge/entries/'.$this->yukine->id, [
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
        ->putJson('/api/knowledge/entries/'.$this->yukine->id, [
            'name' => 'Yukine Updated',
            'type' => 'project',
            'expected_version' => 999, // Wrong version
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expected_version']);
});

it('creates a new knowledge link via API', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/knowledge/links', [
            'from_id' => $this->melfina->id,
            'to_id' => $this->yukine->id,
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
        ->postJson('/api/knowledge/links', [
            'from_id' => '',
            'to_id' => '',
            'predicate' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from_id', 'to_id', 'predicate']);
});

it('deletes a knowledge entry via API', function () {
    $this->actingAs($this->owner)
        ->deleteJson('/api/knowledge/entries/'.$this->yukine->id)
        ->assertOk()
        ->assertJsonStructure([
            'message',
        ]);
    
    // Verify it was deleted
    $this->assertDatabaseMissing('knowledge_entries', ['id' => $this->yukine->id]);
});

it('deletes a knowledge link via API', function () {
    $this->actingAs($this->owner)
        ->deleteJson('/api/knowledge/links/'.$this->link->id)
        ->assertOk()
        ->assertJsonStructure([
            'message',
        ]);
    
    // Verify it was deleted
    $this->assertDatabaseMissing('knowledge_links', ['id' => $this->link->id]);
});
