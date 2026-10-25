<?php

use App\Models\KnowledgeEntry;
use App\Models\KnowledgeLink;
use App\Models\KnowledgePredicate;
use App\Models\User;
use App\Services\KnowledgeGraph;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->graph = app(KnowledgeGraph::class);
});

it('returns constellation with nodes positioned in layout', function () {
    // Create a simple graph: A -> B -> C
    $a = KnowledgeEntry::factory()->create([
        'name' => 'Node A', 'name_lower' => 'node a', 'type' => 'concept',
    ]);
    
    $b = KnowledgeEntry::factory()->create([
        'name' => 'Node B', 'name_lower' => 'node b', 'type' => 'concept',
    ]);
    
    $c = KnowledgeEntry::factory()->create([
        'name' => 'Node C', 'name_lower' => 'node c', 'type' => 'concept',
    ]);
    
    // Connect A -> B
    $this->graph->connect(
        author: 'owner',
        authorKind: \App\Models\KnowledgeAuthorKind::Owner,
        from: 'Node A',
        to: 'Node B',
        predicate: KnowledgePredicate::RelatedTo,
    );
    
    // Connect B -> C
    $this->graph->connect(
        author: 'owner',
        authorKind: \App\Models\KnowledgeAuthorKind::Owner,
        from: 'Node B',
        to: 'Node C',
        predicate: KnowledgePredicate::RelatedTo,
    );
    
    // Get constellation focused on A
    $constellation = $this->graph->constellation($a->id, 2, 10);
    
    // Should have all 3 nodes
    expect($constellation['nodes'])->toHaveCount(3)
        ->and($constellation['links'])->toHaveCount(2);
    
    // Verify node data structure
    $nodeNames = array_column($constellation['nodes'], 'name');
    expect($nodeNames)->toContain('Node A')
        ->toContain('Node B')
        ->toContain('Node C');
});

it('returns constellation with limited hops', function () {
    // Create a chain: A -> B -> C
    $a = KnowledgeEntry::factory()->create([
        'name' => 'Node A', 'name_lower' => 'node a', 'type' => 'concept',
    ]);
    
    $b = KnowledgeEntry::factory()->create([
        'name' => 'Node B', 'name_lower' => 'node b', 'type' => 'concept',
    ]);
    
    $c = KnowledgeEntry::factory()->create([
        'name' => 'Node C', 'name_lower' => 'node c', 'type' => 'concept',
    ]);
    
    // Connect A -> B -> C
    $this->graph->connect(
        author: 'owner',
        authorKind: \App\Models\KnowledgeAuthorKind::Owner,
        from: 'Node A',
        to: 'Node B',
        predicate: KnowledgePredicate::RelatedTo,
    );
    
    $this->graph->connect(
        author: 'owner',
        authorKind: \App\Models\KnowledgeAuthorKind::Owner,
        from: 'Node B',
        to: 'Node C',
        predicate: KnowledgePredicate::RelatedTo,
    );
    
    // Get constellation with 1 hop from A - should include A, B, and C (2 hops total)
    $constellation = $this->graph->constellation($a->id, 1, 10);
    
    // Should have A, B, and C
    $nodeNames = array_column($constellation['nodes'], 'name');
    expect($nodeNames)->toContain('Node A')
        ->toContain('Node B')
        ->toContain('Node C');
});

it('returns constellation with node limit', function () {
    // Create many nodes
    for ($i = 0; $i < 10; $i++) {
        KnowledgeEntry::factory()->create([
            'name' => 'Node'.$i, 'name_lower' => 'node'.$i, 'type' => 'concept',
        ]);
    }
    
    // Get constellation with limit of 5
    $constellation = $this->graph->constellation(null, 2, 5);
    
    // Should have at most 5 nodes
    expect(count($constellation['nodes']))->toBeLessThanOrEqual(5);
});

it('returns full constellation when no focus specified', function () {
    // Create 3 connected nodes
    $a = KnowledgeEntry::factory()->create([
        'name' => 'Node A', 'name_lower' => 'node a', 'type' => 'concept',
    ]);
    
    $b = KnowledgeEntry::factory()->create([
        'name' => 'Node B', 'name_lower' => 'node b', 'type' => 'concept',
    ]);
    
    $c = KnowledgeEntry::factory()->create([
        'name' => 'Node C', 'name_lower' => 'node c', 'type' => 'concept',
    ]);
    
    $this->graph->connect(
        author: 'owner',
        authorKind: \App\Models\KnowledgeAuthorKind::Owner,
        from: 'Node A',
        to: 'Node B',
        predicate: KnowledgePredicate::RelatedTo,
    );
    
    $this->graph->connect(
        author: 'owner',
        authorKind: \App\Models\KnowledgeAuthorKind::Owner,
        from: 'Node B',
        to: 'Node C',
        predicate: KnowledgePredicate::RelatedTo,
    );
    
    // Get full constellation (no focus)
    $constellation = $this->graph->constellation(null, 2, 10);
    
    // Should have all nodes
    $nodeNames = array_column($constellation['nodes'], 'name');
    expect($nodeNames)->toContain('Node A')
        ->toContain('Node B')
        ->toContain('Node C');
});
