<?php

use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgePredicate;
use App\Models\User;
use App\Services\KnowledgeGraph;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->graph = app(KnowledgeGraph::class);
});

it('returns constellation with nodes and links', function () {
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
        authorKind: KnowledgeAuthorKind::Owner,
        from: 'Node A',
        to: 'Node B',
        predicate: KnowledgePredicate::RelatedTo,
    );

    $this->graph->connect(
        author: 'owner',
        authorKind: KnowledgeAuthorKind::Owner,
        from: 'Node B',
        to: 'Node C',
        predicate: KnowledgePredicate::RelatedTo,
    );

    $constellation = $this->graph->constellation($a->id, 2, 10);

    expect($constellation['nodes'])->toHaveCount(3)
        ->and($constellation['links'])->toHaveCount(2);

    $nodeNames = array_column($constellation['nodes'], 'name');
    expect($nodeNames)->toContain('Node A')
        ->toContain('Node B')
        ->toContain('Node C');
});

it('limits the constellation to the requested hops', function () {
    // A chain of six, plus enough filler that the graph is larger than the
    // limit — only then does the focused walk decide what is visible.
    $chain = collect(range(1, 6))->map(fn (int $i) => KnowledgeEntry::factory()->create([
        'name' => "Node {$i}", 'name_lower' => "node {$i}", 'type' => 'concept',
    ]));

    foreach ($chain->slice(0, -1) as $index => $entry) {
        $this->graph->connect(
            author: 'owner',
            authorKind: KnowledgeAuthorKind::Owner,
            from: $entry->name,
            to: $chain[$index + 1]->name,
            predicate: KnowledgePredicate::RelatedTo,
        );
    }

    for ($i = 0; $i < 6; $i++) {
        KnowledgeEntry::factory()->create([
            'name' => "Filler {$i}", 'name_lower' => "filler {$i}", 'type' => 'concept',
        ]);
    }

    // One hop from the head of the chain: the head and its neighbour.
    $oneHop = $this->graph->constellation($chain->first()->id, 1, 5);
    expect($oneHop['nodes'])->toHaveCount(2);

    // Two hops: the head, its neighbour, and the one after.
    $twoHops = $this->graph->constellation($chain->first()->id, 2, 5);
    expect($twoHops['nodes'])->toHaveCount(3);

    // Five hops reach the end of a six-node chain.
    $fiveHops = $this->graph->constellation($chain->first()->id, 5, 5);
    expect($fiveHops['nodes'])->toHaveCount(5);
});

it('returns constellation with node limit', function () {
    for ($i = 0; $i < 10; $i++) {
        KnowledgeEntry::factory()->create([
            'name' => 'Node'.$i, 'name_lower' => 'node'.$i, 'type' => 'concept',
        ]);
    }

    $constellation = $this->graph->constellation(null, 2, 5);

    expect($constellation['nodes'])->toHaveCount(5);
});

it('returns full constellation when no focus specified', function () {
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
        authorKind: KnowledgeAuthorKind::Owner,
        from: 'Node A',
        to: 'Node B',
        predicate: KnowledgePredicate::RelatedTo,
    );

    $this->graph->connect(
        author: 'owner',
        authorKind: KnowledgeAuthorKind::Owner,
        from: 'Node B',
        to: 'Node C',
        predicate: KnowledgePredicate::RelatedTo,
    );

    $constellation = $this->graph->constellation(null, 2, 10);

    $nodeNames = array_column($constellation['nodes'], 'name');
    expect($nodeNames)->toContain('Node A')
        ->toContain('Node B')
        ->toContain('Node C');
});
