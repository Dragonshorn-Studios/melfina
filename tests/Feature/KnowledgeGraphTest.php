<?php

use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeLink;
use App\Models\KnowledgeLinkStatus;
use App\Models\KnowledgeObservationKind;
use App\Models\KnowledgePredicate;
use App\Services\KnowledgeGraph;

beforeEach(function () {
    $this->graph = app(KnowledgeGraph::class);

    $this->yukine = KnowledgeEntry::factory()->create([
        'name' => 'Yukine', 'name_lower' => 'yukine', 'type' => 'project',
        'summary' => 'Discord bridge',
    ]);
    $this->botbox = KnowledgeEntry::factory()->create([
        'name' => 'Botbox', 'name_lower' => 'botbox', 'type' => 'service',
        'summary' => 'home server',
    ]);
});

it('asserts a typed link and records an observation', function () {
    $result = $this->graph->connect(
        author: 'grokbot',
        authorKind: KnowledgeAuthorKind::Agent,
        from: 'Yukine',
        to: 'Botbox',
        predicate: KnowledgePredicate::RunsOn,
        note: 'seen in deploy log',
        source: 'deploy 1.2',
    );

    expect($result->ok)->toBeTrue()
        ->and($result->created)->toBeTrue()
        ->and($result->link->predicate)->toBe(KnowledgePredicate::RunsOn)
        ->and($result->link->from_entry_id)->toBe($this->yukine->id)
        ->and($result->link->to_entry_id)->toBe($this->botbox->id)
        ->and($result->link->confidence)->toBe(KnowledgeGraph::CONFIDENCE_PER_AGENT)
        ->and($result->observation->kind)->toBe(KnowledgeObservationKind::LinkAssert)
        ->and($result->observation->payload['note'])->toBe('seen in deploy log');
});

it('canonicalizes inverse predicates by swapping endpoints', function () {
    $result = $this->graph->connect(
        author: 'grokbot',
        authorKind: KnowledgeAuthorKind::Agent,
        from: 'Botbox',
        to: 'Yukine',
        predicate: KnowledgePredicate::Hosts,
    );

    expect($result->ok)->toBeTrue()
        ->and($result->link->predicate)->toBe(KnowledgePredicate::RunsOn)
        ->and($result->link->from_entry_id)->toBe($this->yukine->id)
        ->and($result->link->to_entry_id)->toBe($this->botbox->id);
});

it('raises confidence when a second distinct agent asserts the same link', function () {
    $this->graph->connect('grokbot', KnowledgeAuthorKind::Agent, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);
    $again = $this->graph->connect('kuchcik', KnowledgeAuthorKind::Agent, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);

    expect($again->link->confidence)->toBe(KnowledgeGraph::CONFIDENCE_PER_AGENT * 2);
});

it('caps same-agent repeats so one mind cannot reach owner confidence', function () {
    $result = null;
    for ($i = 0; $i < 8; $i++) {
        $result = $this->graph->connect('grokbot', KnowledgeAuthorKind::Agent, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);
    }

    $expected = KnowledgeGraph::CONFIDENCE_PER_AGENT
        + KnowledgeGraph::CONFIDENCE_PER_REPEAT * KnowledgeGraph::REPEAT_CAP_PER_AGENT;

    expect($result->link->confidence)->toBe($expected)
        ->and($result->link->confidence)->toBeLessThan(KnowledgeGraph::CONFIDENCE_OWNER_ASSERT);
});

it('jumps confidence when the owner confirms', function () {
    $this->graph->connect('grokbot', KnowledgeAuthorKind::Agent, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);
    $link = KnowledgeLink::query()->sole();
    $confirmed = $this->graph->confirm($link);

    expect($confirmed->confidence)->toBeGreaterThanOrEqual(KnowledgeGraph::CONFIDENCE_OWNER_FLOOR)
        ->and($confirmed->permanent)->toBeFalse();
});

it('marks a link permanent so agents cannot retract it', function () {
    $this->graph->connect('grokbot', KnowledgeAuthorKind::Agent, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);
    $link = KnowledgeLink::query()->sole();
    $locked = $this->graph->setPermanent($link, true);

    expect($locked->permanent)->toBeTrue()
        ->and($locked->confidence)->toBeGreaterThanOrEqual(KnowledgeGraph::CONFIDENCE_OWNER_FLOOR);

    $denied = $this->graph->connect(
        author: 'grokbot',
        authorKind: KnowledgeAuthorKind::Agent,
        from: 'Yukine',
        to: 'Botbox',
        predicate: KnowledgePredicate::RunsOn,
        op: KnowledgeObservationKind::LinkRetract,
    );

    expect($denied->ok)->toBeFalse()
        ->and($denied->error)->toContain('permanent')
        ->and($link->fresh()->status)->toBe(KnowledgeLinkStatus::Active);
});

it('lets the owner retract a permanent link', function () {
    $this->graph->connect('owner', KnowledgeAuthorKind::Owner, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);
    $link = KnowledgeLink::query()->sole();
    $this->graph->setPermanent($link, true);

    $gone = $this->graph->connect(
        author: 'owner',
        authorKind: KnowledgeAuthorKind::Owner,
        from: 'Yukine',
        to: 'Botbox',
        predicate: KnowledgePredicate::RunsOn,
        op: KnowledgeObservationKind::LinkRetract,
    );

    expect($gone->ok)->toBeTrue()
        ->and($gone->link->status)->toBe(KnowledgeLinkStatus::Retracted)
        ->and($gone->link->confidence)->toBe(0)
        ->and($gone->link->permanent)->toBeFalse();
});

it('does not create a ghost link when retracting a triple that was never asserted', function () {
    $result = $this->graph->connect(
        author: 'grokbot',
        authorKind: KnowledgeAuthorKind::Agent,
        from: 'Yukine',
        to: 'Botbox',
        predicate: KnowledgePredicate::RunsOn,
        op: KnowledgeObservationKind::LinkRetract,
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('No such link')
        ->and(KnowledgeLink::query()->count())->toBe(0);
});

it('marks a link retracted when its last asserter withdraws', function () {
    $this->graph->connect('grokbot', KnowledgeAuthorKind::Agent, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);
    $gone = $this->graph->connect(
        author: 'grokbot',
        authorKind: KnowledgeAuthorKind::Agent,
        from: 'Yukine',
        to: 'Botbox',
        predicate: KnowledgePredicate::RunsOn,
        op: KnowledgeObservationKind::LinkRetract,
    );

    expect($gone->ok)->toBeTrue()
        ->and($gone->link->status)->toBe(KnowledgeLinkStatus::Retracted)
        ->and($gone->link->confidence)->toBe(0);

    $snap = $this->graph->constellation();
    expect($snap['links'])->toHaveCount(0);
});

it('does not write a link when an endpoint is missing', function () {
    $result = $this->graph->connect(
        'grokbot',
        KnowledgeAuthorKind::Agent,
        'Yukine',
        'No Such Host',
        KnowledgePredicate::RunsOn,
    );

    expect($result->ok)->toBeFalse()
        ->and($result->unresolved)->toBe(['to' => 'No Such Host'])
        ->and(KnowledgeLink::query()->count())->toBe(0);
});

it('creates a stub entity when create_missing is set', function () {
    $result = $this->graph->connect(
        author: 'grokbot',
        authorKind: KnowledgeAuthorKind::Agent,
        from: 'Yukine',
        to: 'New Box',
        predicate: KnowledgePredicate::RunsOn,
        createMissing: true,
    );

    expect($result->ok)->toBeTrue();
    $stub = KnowledgeEntry::lookup('New Box');
    expect($stub)->not->toBeNull()
        ->and($stub->type)->toBe('concept')
        ->and($result->link->to_entry_id)->toBe($stub->id);
});

it('attaches a named event entity that involves both ends', function () {
    $result = $this->graph->connect(
        author: 'grokbot',
        authorKind: KnowledgeAuthorKind::Agent,
        from: 'Yukine',
        to: 'Botbox',
        predicate: KnowledgePredicate::RunsOn,
        eventName: 'Yukine deploy 1.2',
    );

    expect($result->ok)->toBeTrue();
    $event = KnowledgeEntry::lookup('Yukine deploy 1.2');
    expect($event)->not->toBeNull()->and($event->type)->toBe('event');

    $involves = KnowledgeLink::query()
        ->where('predicate', KnowledgePredicate::Involves)
        ->get();
    expect($involves)->toHaveCount(2);
});

it('returns a neighborhood with inverse predicate names on incoming hops', function () {
    $this->graph->connect('grokbot', KnowledgeAuthorKind::Agent, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);

    $fromYukine = $this->graph->neighborhood($this->yukine->fresh());
    expect($fromYukine['outgoing'])->toHaveCount(1)
        ->and($fromYukine['outgoing'][0]['predicate'])->toBe('runs_on')
        ->and($fromYukine['outgoing'][0]['name'])->toBe('Botbox');

    $fromBotbox = $this->graph->neighborhood($this->botbox->fresh());
    expect($fromBotbox['incoming'])->toHaveCount(1)
        ->and($fromBotbox['incoming'][0]['predicate'])->toBe('hosts')
        ->and($fromBotbox['incoming'][0]['name'])->toBe('Yukine');
});

it('migrates existing related names into related_to links', function () {
    $melfina = KnowledgeEntry::factory()->create([
        'name' => 'Melfina', 'name_lower' => 'melfina', 'summary' => 'Imp brain',
    ]);
    $shiori = KnowledgeEntry::factory()->create([
        'name' => 'Shiori', 'name_lower' => 'shiori',
        'related' => ['Melfina', 'Gone Project'],
        'updated_by' => 'grokbot',
    ]);

    $this->graph->connect(
        'grokbot',
        KnowledgeAuthorKind::Agent,
        'Shiori',
        'Melfina',
        KnowledgePredicate::RelatedTo,
        source: 'migrated from related',
    );

    $related = $shiori->fresh()->resolvedRelated();
    expect($related['entries'])->toHaveCount(1)
        ->and($related['entries'][0]['name'])->toBe('Melfina')
        ->and($related['unresolved'])->toBe(['Gone Project']);
});

it('still resolves leftover related names that exist but have no graph edge', function () {
    KnowledgeEntry::factory()->create([
        'name' => 'Melfina', 'name_lower' => 'melfina', 'summary' => 'Imp brain',
    ]);
    $shiori = KnowledgeEntry::factory()->create([
        'name' => 'Shiori', 'name_lower' => 'shiori',
        'related' => ['Melfina', 'Yukine', 'Gone Project'],
    ]);

    $this->graph->connect('grokbot', KnowledgeAuthorKind::Agent, 'Shiori', 'Botbox', KnowledgePredicate::RelatedTo);

    $related = $shiori->fresh()->resolvedRelated();
    $names = collect($related['entries'])->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['Botbox', 'Melfina', 'Yukine'])
        ->and($related['unresolved'])->toBe(['Gone Project']);
});

it('lets the owner retarget a predicate', function () {
    $this->graph->connect('owner', KnowledgeAuthorKind::Owner, 'Yukine', 'Botbox', KnowledgePredicate::RelatedTo);
    $link = KnowledgeLink::query()->sole();

    $fixed = $this->graph->retarget($link, KnowledgePredicate::RunsOn, $this->yukine, $this->botbox);

    expect($fixed->ok)->toBeTrue()
        ->and($fixed->link->predicate)->toBe(KnowledgePredicate::RunsOn);
});

it('omits retracted links from the constellation snapshot', function () {
    $this->graph->connect('owner', KnowledgeAuthorKind::Owner, 'Yukine', 'Botbox', KnowledgePredicate::RunsOn);
    $this->graph->connect(
        'owner',
        KnowledgeAuthorKind::Owner,
        'Yukine',
        'Botbox',
        KnowledgePredicate::RunsOn,
        op: KnowledgeObservationKind::LinkRetract,
    );

    $snap = $this->graph->constellation();
    expect($snap['nodes'])->toHaveCount(2)
        ->and($snap['links'])->toHaveCount(0);
});
