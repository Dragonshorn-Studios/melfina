<?php

use App\Mcp\Tools\KnowledgeUpsert;
use App\Mcp\Tools\ReadInstructions;
use App\Models\Agent;
use App\Models\AgentAddressingPreference;
use App\Models\AgentEntityReference;
use App\Models\AgentEntityReferenceKind;
use App\Models\Instruction;
use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeEntryRedirect;
use App\Models\KnowledgeIdentifier;
use App\Models\KnowledgeIdentifierKind;
use App\Models\KnowledgeLink;
use App\Models\KnowledgePredicate;
use App\Models\User;
use App\Services\KnowledgeGraph;
use App\Services\KnowledgeIdentityAdmin;
use App\Services\KnowledgeIdentityContext;
use App\Services\KnowledgeIdentityResolver;
use App\Support\KnowledgeName;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

function knowledgeIdentityResult(array $result): array
{
    return json_decode($result['content'][0]['text'] ?? '', true) ?? [];
}

function bindRughaltWorld(Agent $imp, ?Agent $other = null): KnowledgeEntry
{
    $person = KnowledgeEntry::factory()->create([
        'name' => 'Rughalt',
        'name_lower' => 'rughalt',
        'type' => 'person',
        'summary' => 'installation operator',
        'aliases' => [],
    ]);

    $admin = app(KnowledgeIdentityAdmin::class);
    $owner = User::query()->where('is_owner', true)->sole();
    $admin->bindOperator($person, $owner);
    $admin->addIdentifier($person, 'Runako', KnowledgeIdentifierKind::Persona);
    $admin->bindReference([$imp], $person, 'Szefowa', AgentEntityReferenceKind::AddressTerm);

    return $person->fresh();
}

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->withKey()->create(['name' => 'grokbot']);
    $this->other = Agent::factory()->withKey('melf_otherkey')->create(['name' => 'miyu']);
    $this->key = 'melf_testkey';

    $this->mcpTool = function (string $tool, array $args = [], ?string $bearer = null) {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $args],
        ], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.($bearer ?? $this->key)]);

        return $response->json('result');
    };

    $this->httpTool = function (string $tool, array $args = [], ?string $bearer = null) {
        return $this->postJson('/api/tools/'.$tool, $args, [
            'Authorization' => 'Bearer '.($bearer ?? $this->key),
        ]);
    };
});

it('resolves Szefowa, Rughalt, and Runako to the same person for the configured imp', function () {
    $person = bindRughaltWorld($this->agent);

    foreach (['Szefowa', 'Rughalt', 'Runako', 'rughalt'] as $needle) {
        $found = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => $needle]));
        expect($found['found'])->toBeTrue()
            ->and($found['entry_id'])->toBe($person->id)
            ->and($found['name'])->toBe('Rughalt')
            ->and($found['resolution']['input'])->toBe($needle);
    }

    expect($found['resolution']['matched_by'])->toBe('canonical_name');

    $title = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa']));
    expect($title['resolution']['matched_by'])->toBe('agent_reference')
        ->and($title['resolution']['scope'])->toBe('agent')
        ->and($person->fresh()->aliases)->not->toContain('Szefowa');

    expect(KnowledgeIdentifier::query()->where('normalized_value', 'szefowa')->exists())->toBeFalse();
});

it('does not let another agent inherit Szefowa, and allows a different binding', function () {
    $person = bindRughaltWorld($this->agent);
    $stranger = KnowledgeEntry::factory()->create([
        'name' => 'Other Human', 'name_lower' => 'other human', 'type' => 'person',
    ]);

    $miss = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa'], 'melf_otherkey'));
    expect($miss['found'])->toBeFalse()
        ->and($miss['hint'])->toContain('external');

    app(KnowledgeIdentityAdmin::class)->bindReference(
        [$this->other],
        $stranger,
        'Szefowa',
        AgentEntityReferenceKind::AddressTerm,
    );

    $theirs = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa'], 'melf_otherkey'));
    expect($theirs['found'])->toBeTrue()
        ->and($theirs['entry_id'])->toBe($stranger->id)
        ->and($theirs['name'])->toBe('Other Human');

    $ours = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa']));
    expect($ours['entry_id'])->toBe($person->id);
});

it('upserts through an alias or scoped title without renaming or cloning the person', function () {
    $person = bindRughaltWorld($this->agent);

    $viaTitle = knowledgeIdentityResult(($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Szefowa',
        'summary' => 'updated via title',
    ]));
    $viaPersona = knowledgeIdentityResult(($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Runako',
        'state' => 'still one person',
    ]));

    expect($viaTitle['updated'])->toBeTrue()
        ->and($viaTitle['created'])->toBeFalse()
        ->and($viaTitle['entry'])->toBe('Rughalt')
        ->and($viaTitle['entry_id'])->toBe($person->id)
        ->and($viaPersona['entry'])->toBe('Rughalt');

    expect(KnowledgeEntry::query()->where('type', 'person')->count())->toBe(1);
    expect($person->fresh()->name)->toBe('Rughalt')
        ->and($person->fresh()->summary)->toBe('updated via title')
        ->and($person->fresh()->state)->toBe('still one person');
});

it('connects through the title using the same foreign key as the canonical name', function () {
    $person = bindRughaltWorld($this->agent);
    $project = KnowledgeEntry::factory()->create([
        'name' => 'Yukine', 'name_lower' => 'yukine', 'type' => 'project',
    ]);

    $viaTitle = knowledgeIdentityResult(($this->mcpTool)('knowledge_connect', [
        'from' => 'Yukine',
        'to' => 'Szefowa',
        'predicate' => 'owned_by',
    ]));
    expect($viaTitle['updated'])->toBeTrue()
        ->and($viaTitle['to'])->toBe('Rughalt')
        ->and($viaTitle['to_id'])->toBe($person->id);

    $link = KnowledgeLink::query()->sole();
    expect($link->to_entry_id)->toBe($person->id)
        ->and($link->from_entry_id)->toBe($project->id);

    knowledgeIdentityResult(($this->mcpTool)('knowledge_connect', [
        'from' => 'Yukine',
        'to' => 'Rughalt',
        'predicate' => 'owned_by',
    ]));
    expect(KnowledgeLink::query()->count())->toBe(1);
});

it('ranks an exact scoped title ahead of body hits and hides other agents titles', function () {
    $person = bindRughaltWorld($this->agent);
    KnowledgeEntry::factory()->create([
        'name' => 'Cookbook',
        'name_lower' => 'cookbook',
        'type' => 'runbook',
        'state' => 'Szefowa asked for this recipe once',
    ]);
    $stranger = KnowledgeEntry::factory()->create([
        'name' => 'Stranger', 'name_lower' => 'stranger', 'type' => 'person',
    ]);
    app(KnowledgeIdentityAdmin::class)->bindReference([$this->other], $stranger, 'Szefowa');

    $found = knowledgeIdentityResult(($this->mcpTool)('knowledge_search', ['query' => 'Szefowa', 'type' => 'person', 'limit' => 5]));
    expect($found['found'])->toBeTrue();
    $names = collect($found['results'])->pluck('name')->all();
    expect($names[0])->toBe('Rughalt')
        ->and($names)->not->toContain('Stranger')
        ->and($found['results'][0]['entry_id'])->toBe($person->id);

    $otherSearch = knowledgeIdentityResult(($this->mcpTool)('knowledge_search', ['query' => 'Szefowa'], 'melf_otherkey'));
    expect(collect($otherSearch['results'])->pluck('name')->all())->not->toContain('Rughalt')
        ->and(collect($otherSearch['results'] ?? [])->pluck('name')->all())->toContain('Stranger');
});

it('rejects id/string mismatches without writing', function () {
    $person = bindRughaltWorld($this->agent);
    $other = KnowledgeEntry::factory()->create([
        'name' => 'Yukine', 'name_lower' => 'yukine', 'type' => 'project',
    ]);

    $conflict = ($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Szefowa',
        'entry_id' => $other->id,
        'summary' => 'should not land',
    ]);
    expect($conflict['isError'] ?? false)->toBeTrue();
    expect($person->fresh()->summary)->toBe('installation operator')
        ->and($other->fresh()->summary)->not->toBe('should not land');

    $connect = knowledgeIdentityResult(($this->mcpTool)('knowledge_connect', [
        'from' => 'Rughalt',
        'from_id' => $other->id,
        'to' => 'Yukine',
        'predicate' => 'related_to',
    ]));
    expect($connect['updated'])->toBeFalse()
        ->and(KnowledgeLink::query()->count())->toBe(0);
});

it('treats a shared name as ambiguous instead of first() or a stub', function () {
    KnowledgeEntry::factory()->create(['name' => 'Alex', 'name_lower' => 'alex', 'type' => 'person']);
    KnowledgeEntry::factory()->create(['name' => 'Alexandra', 'name_lower' => 'alexandra', 'type' => 'person']);
    app(KnowledgeIdentityAdmin::class)->addIdentifier(
        KnowledgeEntry::query()->where('name_lower', 'alex')->sole(),
        'Alexandra',
        KnowledgeIdentifierKind::Alias,
    );

    $lookup = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Alexandra']));
    expect($lookup['found'])->toBeFalse()
        ->and($lookup['code'])->toBe('ambiguous_reference')
        ->and($lookup['hint'] ?? '')->not->toContain('external')
        ->and($lookup['candidates'])->toHaveCount(2);

    $before = KnowledgeEntry::query()->count();
    $connect = knowledgeIdentityResult(($this->mcpTool)('knowledge_connect', [
        'from' => 'Alexandra',
        'to' => 'ghost-host',
        'predicate' => 'related_to',
        'create_missing' => true,
    ]));
    expect($connect['updated'])->toBeFalse()
        ->and($connect['code'])->toBe('ambiguous_reference')
        ->and(KnowledgeEntry::query()->count())->toBe($before)
        ->and(KnowledgeLink::query()->count())->toBe(0);
});

it('does not let two writes with the same expected_version clobber each other', function () {
    bindRughaltWorld($this->agent);

    $first = knowledgeIdentityResult(($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Rughalt',
        'summary' => 'first writer',
        'expected_version' => 1,
    ]));
    $second = knowledgeIdentityResult(($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Szefowa',
        'summary' => 'second writer',
        'expected_version' => 1,
    ]));

    expect($first['updated'])->toBeTrue()
        ->and($second['conflict'])->toBeTrue()
        ->and(KnowledgeEntry::query()->where('name_lower', 'rughalt')->sole()->summary)->toBe('first writer');
});

it('rolls back a stub when the other connect endpoint cannot be resolved', function () {
    KnowledgeEntry::factory()->create(['name' => 'Alex', 'name_lower' => 'alex', 'type' => 'person']);
    KnowledgeEntry::factory()->create(['name' => 'Alexandra', 'name_lower' => 'alexandra', 'type' => 'person']);
    app(KnowledgeIdentityAdmin::class)->addIdentifier(
        KnowledgeEntry::query()->where('name_lower', 'alex')->sole(),
        'Twin',
        KnowledgeIdentifierKind::Alias,
    );
    app(KnowledgeIdentityAdmin::class)->addIdentifier(
        KnowledgeEntry::query()->where('name_lower', 'alexandra')->sole(),
        'Twin',
        KnowledgeIdentifierKind::Alias,
    );

    $before = KnowledgeEntry::query()->count();
    $result = knowledgeIdentityResult(($this->mcpTool)('knowledge_connect', [
        'from' => 'Brand New Box',
        'to' => 'Twin',
        'predicate' => 'runs_on',
        'create_missing' => true,
    ]));

    expect($result['updated'])->toBeFalse()
        ->and($result['code'])->toBe('ambiguous_reference')
        ->and(KnowledgeEntry::query()->count())->toBe($before)
        ->and(KnowledgeEntry::query()->where('name_lower', 'brand new box')->exists())->toBeFalse();
});

it('keeps scoped references when aliases are replaced, and vice versa', function () {
    $person = bindRughaltWorld($this->agent);
    app(KnowledgeIdentityAdmin::class)->addIdentifier($person, 'Ruge', KnowledgeIdentifierKind::Handle);

    knowledgeIdentityResult(($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Rughalt',
        'aliases' => ['rug'],
    ]));

    $person->refresh();
    expect($person->aliases)->toBe(['rug'])
        ->and($person->identifiers()->where('kind', KnowledgeIdentifierKind::Persona)->sole()->value)->toBe('Runako')
        ->and($person->identifiers()->where('kind', KnowledgeIdentifierKind::Handle)->sole()->value)->toBe('Ruge')
        ->and(AgentEntityReference::query()->where('value', 'Szefowa')->exists())->toBeTrue();

    knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa']));
    expect(knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Runako']))['entry_id'])->toBe($person->id);
});

it('projects a new alias identifier onto JSON so the next owner save keeps it', function () {
    $person = bindRughaltWorld($this->agent);
    app(KnowledgeIdentityAdmin::class)->addIdentifier($person, 'Rug', KnowledgeIdentifierKind::Alias);

    $person->refresh();
    expect($person->aliases)->toBe(['Rug']);
    expect(knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Rug']))['entry_id'])->toBe($person->id);

    $this->actingAs($this->owner)
        ->put(route('console.knowledge.update', $person), [
            'name' => 'Rughalt',
            'type' => 'person',
            'aliases' => implode("\n", $person->aliases ?? []),
            'summary' => $person->summary,
            'state' => $person->state,
            'expected_version' => $person->version,
        ])
        ->assertRedirect();

    expect($person->fresh()->identifiers()->where('normalized_value', 'rug')->exists())->toBeTrue()
        ->and($person->fresh()->aliases)->toContain('Rug');
});

it('lets the owner remove an identifier from the operator person', function () {
    $person = bindRughaltWorld($this->agent);
    $alias = app(KnowledgeIdentityAdmin::class)->addIdentifier($person, 'Rug', KnowledgeIdentifierKind::Alias);
    $this->owner->refresh();

    $this->actingAs($this->owner)
        ->get(route('console.dashboard'))
        ->assertOk()
        ->assertSee('OPERATOR NAMES', false)
        ->assertSee('Rug', false)
        ->assertSee('A global nickname anyone can look up', false)
        ->assertSee('A public or account handle that names this person', false)
        ->assertSee('A human persona name of this person', false);

    $this->actingAs($this->owner)
        ->from(route('console.dashboard'))
        ->delete(route('console.knowledge.identifiers.destroy', [$person, $alias]))
        ->assertRedirect(route('console.dashboard'));

    expect($person->fresh()->identifiers()->where('normalized_value', 'rug')->exists())->toBeFalse()
        ->and($person->fresh()->aliases)->not->toContain('Rug');

    $lookup = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Rug']));
    expect($lookup['found'] ?? true)->toBeFalse();
});

it('lets the owner remove an identifier from the knowledge edit page', function () {
    $person = bindRughaltWorld($this->agent);
    $handle = app(KnowledgeIdentityAdmin::class)->addIdentifier($person, 'Ruge', KnowledgeIdentifierKind::Handle);

    $this->actingAs($this->owner)
        ->get(route('console.knowledge.edit', $person))
        ->assertOk()
        ->assertSee('Ruge', false)
        ->assertSee('A global nickname anyone can look up', false)
        ->assertSee('A public or account handle that names this person', false)
        ->assertSee('A human persona name of this person', false);

    $this->actingAs($this->owner)
        ->from(route('console.knowledge.edit', $person))
        ->delete(route('console.knowledge.identifiers.destroy', [$person, $handle]))
        ->assertRedirect(route('console.knowledge.edit', $person));

    expect($person->fresh()->identifiers()->where('normalized_value', 'ruge')->exists())->toBeFalse();
});

it('does not remove an identifier from another entry', function () {
    $person = bindRughaltWorld($this->agent);
    $other = KnowledgeEntry::factory()->create([
        'name' => 'Stranger', 'name_lower' => 'stranger', 'type' => 'person',
    ]);
    $foreign = app(KnowledgeIdentityAdmin::class)->addIdentifier($other, 'Nomad', KnowledgeIdentifierKind::Alias);

    $this->actingAs($this->owner)
        ->delete(route('console.knowledge.identifiers.destroy', [$person, $foreign]))
        ->assertNotFound();

    expect($other->fresh()->identifiers()->where('normalized_value', 'nomad')->exists())->toBeTrue();
});

it('copies a colliding source identifier once when repairing duplicates', function () {
    $canonical = KnowledgeEntry::factory()->create([
        'name' => 'Rughalt', 'name_lower' => 'rughalt', 'type' => 'person',
    ]);
    $first = KnowledgeEntry::factory()->create([
        'name' => 'Rughalt A', 'name_lower' => 'rughalt a', 'type' => 'person',
    ]);
    $second = KnowledgeEntry::factory()->create([
        'name' => 'Rughalt B', 'name_lower' => 'rughalt b', 'type' => 'person',
    ]);
    $admin = app(KnowledgeIdentityAdmin::class);
    $admin->addIdentifier($first, 'Runako', KnowledgeIdentifierKind::Persona);
    $admin->addIdentifier($second, 'Runako', KnowledgeIdentifierKind::Persona);

    $report = $admin->repairDuplicates([
        'canonical_id' => $canonical->id,
        'source_ids' => [$first->id, $second->id],
    ], dryRun: false);

    expect($canonical->fresh()->identifiers()->where('normalized_value', 'runako')->count())->toBe(1)
        ->and(collect($report['identifier_copies'])->pluck('value')->all())->toBe(['Runako'])
        ->and(collect($report['identifier_collisions'])->pluck('from')->all())->toBe([$second->id]);
});

it('does not let an agent retype the operator person', function () {
    $person = bindRughaltWorld($this->agent);

    $result = ($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Rughalt',
        'entry_id' => $person->id,
        'type' => 'concept',
    ]);
    expect($result['isError'] ?? false)->toBeTrue();

    $person->refresh();
    expect($person->type)->toBe('person');

    $lookup = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'operator']));
    expect($lookup['found'])->toBeTrue()
        ->and($lookup['entry_id'])->toBe($person->id);
});

it('does not let an agent change the operator or another agents references', function () {
    $person = bindRughaltWorld($this->agent);
    $stranger = KnowledgeEntry::factory()->create([
        'name' => 'Intruder', 'name_lower' => 'intruder', 'type' => 'person',
    ]);

    ($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Intruder',
        'summary' => 'not the operator',
    ]);

    expect($this->owner->fresh()->operator_entry_id)->toBe($person->id);
    expect(AgentEntityReference::query()->where('agent_id', $this->other->id)->count())->toBe(0);

    $this->post(route('console.agents.references.store', $this->other), [
        'value' => 'Szefowa',
        'entry_id' => $stranger->id,
        'kind' => 'address_term',
    ])->assertRedirect(route('login'));
});

it('does not grant owner privileges from the operator person id', function () {
    $person = bindRughaltWorld($this->agent);

    ($this->httpTool)('knowledge_lookup', ['query' => 'operator'])->assertOk();

    $this->get(route('console.knowledge.index'))->assertRedirect(route('login'));
    $this->actingAs($this->owner)
        ->get(route('console.knowledge.index'))
        ->assertOk();

    $plain = User::factory()->create(['is_owner' => false, 'operator_entry_id' => $person->id]);
    $this->actingAs($plain)->get(route('console.knowledge.index'))->assertForbidden();
    $this->actingAs($plain)
        ->put(route('console.mcp.operator-binding.store'), ['operator_entry' => (string) $person->id])
        ->assertForbidden();
});

it('reports missing, invalid, and inconsistent operator setup', function () {
    $resolver = app(KnowledgeIdentityResolver::class);

    $missing = $resolver->resolve(KnowledgeIdentityContext::forAgent($this->agent), query: 'operator');
    expect($missing->found())->toBeFalse();

    $service = KnowledgeEntry::factory()->create([
        'name' => 'NotAPerson', 'name_lower' => 'notaperson', 'type' => 'service',
    ]);
    $this->owner->update(['operator_entry_id' => $service->id]);
    $invalid = $resolver->operatorBinding();
    expect($invalid['status']->value)->toBe('inconsistent');

    $lookup = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'operator']));
    expect($lookup['found'])->toBeFalse()
        ->and($lookup['code'])->toBe('inconsistent_identity');
});

it('keeps legacy MCP and HTTP contracts working with additive fields', function () {
    $person = bindRughaltWorld($this->agent);

    $mcp = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Rughalt']));
    $http = ($this->httpTool)('knowledge_lookup', ['query' => 'Szefowa'])->assertOk();

    expect($mcp['found'])->toBeTrue()
        ->and($mcp['name'])->toBe('Rughalt')
        ->and($mcp)->toHaveKey('entry_id')
        ->and($mcp)->toHaveKey('resolution');
    expect($http->json('result.found'))->toBeTrue()
        ->and($http->json('result.entry_id'))->toBe($person->id)
        ->and($http->json('result.name'))->toBe('Rughalt');
});

it('leaves Always address user as Szefowa byte-for-byte and ignores addressing preferences', function () {
    $person = bindRughaltWorld($this->agent);
    $instruction = 'Always address user as Szefowa';
    Instruction::factory()->for($this->agent)->create([
        'content' => $instruction,
        'layers' => ['Be terse.'],
    ]);
    AgentAddressingPreference::factory()->create([
        'agent_id' => $this->agent->id,
        'person_entry_id' => $person->id,
        'form' => 'Runako',
        'context' => 'default',
    ]);

    $payload = ReadInstructions::payload($this->agent->fresh('instruction'));
    expect($payload['content'])->toBe($instruction)
        ->and($payload['layers'])->toBe(['Be terse.'])
        ->and($payload['identity_context']['operator_entry_id'])->toBe($person->id)
        ->and(json_encode($payload['layers']))->not->toContain('Runako')
        ->and($payload['content'])->not->toContain('Runako');

    expect($payload['content'])->toBe('Always address user as Szefowa');
});

it('does not leak one agents context into the next request', function () {
    bindRughaltWorld($this->agent);
    $stranger = KnowledgeEntry::factory()->create([
        'name' => 'MiyuBoss', 'name_lower' => 'miyuboss', 'type' => 'person',
    ]);
    app(KnowledgeIdentityAdmin::class)->bindReference([$this->other], $stranger, 'Szefowa');

    $first = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa']));
    $second = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa'], 'melf_otherkey'));
    $third = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa']));

    expect($first['name'])->toBe('Rughalt')
        ->and($second['name'])->toBe('MiyuBoss')
        ->and($third['name'])->toBe('Rughalt');
});

it('normalizes identity without stripping Polish letters', function () {
    expect(KnowledgeName::normalize('  Szefowa  '))->toBe('szefowa')
        ->and(KnowledgeName::normalize('Żółć'))->toBe('żółć')
        ->and(KnowledgeName::normalize('Żółć'))->not->toBe('zolc');
});

it('refuses to register a scoped title that conceals a different global person', function () {
    bindRughaltWorld($this->agent);
    $other = KnowledgeEntry::factory()->create([
        'name' => 'Other Human', 'name_lower' => 'other human', 'type' => 'person',
    ]);

    expect(fn () => app(KnowledgeIdentityAdmin::class)->bindReference(
        [$this->other],
        $other,
        'Rughalt',
    ))->toThrow(ValidationException::class);
});

it('backfills aliases idempotently and reports collisions', function () {
    KnowledgeEntry::factory()->create([
        'name' => 'Yukine', 'name_lower' => 'yukine', 'aliases' => ['yuki', 'yuki'],
    ]);
    KnowledgeEntry::factory()->create([
        'name' => 'YukiTwo', 'name_lower' => 'yukitwo', 'aliases' => ['yuki'],
    ]);

    Artisan::call('knowledge:backfill-identifiers');
    $first = KnowledgeIdentifier::query()->count();
    Artisan::call('knowledge:backfill-identifiers');
    expect(KnowledgeIdentifier::query()->count())->toBe($first);

    $collisions = app(KnowledgeIdentityAdmin::class)->identifierCollisions();
    expect(collect($collisions)->pluck('normalized'))->toContain('yuki');
});

it('repairs a duplicate Szefowa entry via redirect without deleting history', function () {
    $canonical = bindRughaltWorld($this->agent);
    $duplicate = KnowledgeEntry::factory()->create([
        'name' => 'Szefowa',
        'name_lower' => 'szefowa',
        'type' => 'person',
        'summary' => 'old duplicate row',
        'state' => 'legacy',
    ]);
    $duplicate->recordVersion('grokbot');
    $project = KnowledgeEntry::factory()->create([
        'name' => 'Yukine', 'name_lower' => 'yukine', 'type' => 'project',
    ]);
    app(KnowledgeGraph::class)->connect(
        'grokbot',
        KnowledgeAuthorKind::Agent,
        'Yukine',
        'Szefowa',
        KnowledgePredicate::OwnedBy,
    );

    $path = storage_path('app/identity-manifest.json');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode([
        'canonical_id' => $canonical->id,
        'source_ids' => [$duplicate->id],
    ]));

    Artisan::call('knowledge:repair-duplicates', ['manifest' => $path]);
    expect(KnowledgeEntryRedirect::query()->count())->toBe(0);

    Artisan::call('knowledge:repair-duplicates', ['manifest' => $path, '--execute' => true]);

    expect(KnowledgeEntry::query()->find($duplicate->id))->not->toBeNull()
        ->and($duplicate->versions()->count())->toBe(1)
        ->and(KnowledgeEntryRedirect::query()->where('from_entry_id', $duplicate->id)->value('to_entry_id'))->toBe($canonical->id);

    $found = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa']));
    expect($found['entry_id'])->toBe($canonical->id)
        ->and($found['name'])->toBe('Rughalt');

    $byOldName = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa']));
    $links = $byOldName['links']['incoming'] ?? [];
    expect(collect($links)->pluck('name')->all())->toContain('Yukine');

    $cycle = app(KnowledgeIdentityAdmin::class)->repairDuplicates([
        'canonical_id' => $duplicate->id,
        'source_ids' => [$canonical->id],
    ], dryRun: true);
    expect(collect($cycle['skipped'])->pluck('reason')->all())->toContain('cycle');
});

it('binds the operator and titles through owner commands without hardcoding them in migrations', function () {
    $person = KnowledgeEntry::factory()->create([
        'name' => 'Rughalt', 'name_lower' => 'rughalt', 'type' => 'person',
    ]);

    Artisan::call('knowledge:bind-operator', ['entry' => 'Rughalt']);
    Artisan::call('knowledge:add-identifier', [
        'entry' => 'Rughalt',
        'value' => 'Runako',
        '--kind' => 'persona',
    ]);
    Artisan::call('knowledge:bind-reference', [
        'entry' => 'Rughalt',
        'value' => 'Szefowa',
        '--agent' => ['grokbot'],
    ]);

    expect($this->owner->fresh()->operator_entry_id)->toBe($person->id);

    $found = knowledgeIdentityResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Szefowa']));
    expect($found['entry_id'])->toBe($person->id);
});

it('refuses to use a person as a named event', function () {
    bindRughaltWorld($this->agent);
    KnowledgeEntry::factory()->create(['name' => 'Yukine', 'name_lower' => 'yukine', 'type' => 'project']);
    KnowledgeEntry::factory()->create(['name' => 'Botbox', 'name_lower' => 'botbox', 'type' => 'service']);

    $result = knowledgeIdentityResult(($this->mcpTool)('knowledge_connect', [
        'from' => 'Yukine',
        'to' => 'Botbox',
        'predicate' => 'runs_on',
        'event' => 'Rughalt',
    ]));

    expect($result['updated'])->toBeFalse()
        ->and($result['code'])->toBe('wrong_entity_type');
});

it('charges the same write quota as before', function () {
    bindRughaltWorld($this->agent);
    RateLimiter::increment('mcp-knowledge:'.$this->agent->id, 60, KnowledgeUpsert::WRITE_LIMIT_PER_MINUTE);

    $blocked = ($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Szefowa',
        'summary' => 'nope',
    ]);
    expect($blocked['isError'] ?? false)->toBeTrue();
});
