<?php

use App\Mcp\Tools\ReadInstructions;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\Instruction;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeIdentifierKind;
use App\Models\User;
use App\Services\KnowledgeIdentityAdmin;
use App\Services\PersonaLinks;
use App\Support\KnowledgeName;

function personaPayload(array $result): array
{
    return json_decode($result['content'][0]['text'] ?? '', true) ?? [];
}

function makePerson(string $name, ?string $copy = null): KnowledgeEntry
{
    return KnowledgeEntry::factory()->create([
        'name' => $name,
        'name_lower' => KnowledgeName::normalize($name),
        'type' => 'person',
        'aliases' => [],
        'instruction_copy' => $copy,
    ]);
}

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->withKey()->create(['name' => 'navigator']);
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
});

it('keeps each presentation and reports they are the same being', function () {
    $base = makePerson('Base', 'Address this person as Base.');
    $presentation = makePerson('Presentation', 'Address this person as Presentation.');
    app(KnowledgeIdentityAdmin::class)->bindOperator($base, $this->owner);
    app(PersonaLinks::class)->link($base, $presentation);
    $this->agent->update(['main_person_entry_id' => $presentation->id]);

    $viaPresentation = personaPayload(($this->mcpTool)('knowledge_lookup', ['query' => 'Presentation']));
    $viaBase = personaPayload(($this->mcpTool)('knowledge_lookup', ['query' => 'Base']));
    $viaRole = personaPayload(($this->mcpTool)('knowledge_lookup', ['query' => 'operator']));

    expect($viaPresentation['name'])->toBe('Presentation')
        ->and($viaPresentation['being']['is_main_person'])->toBeTrue()
        ->and($viaPresentation['being']['same_as_operator'])->toBeTrue()
        ->and($viaPresentation['being']['operator_name'])->toBe('Base')
        ->and($viaPresentation['being']['main_person_name'])->toBe('Presentation')
        ->and(collect($viaPresentation['being']['entries'])->pluck('name')->sort()->values()->all())
        ->toBe(['Base', 'Presentation']);

    expect($viaBase['name'])->toBe('Base')
        ->and($viaBase['being']['is_main_person'])->toBeFalse()
        ->and($viaBase['being']['same_as_operator'])->toBeTrue()
        ->and($viaBase['being']['main_person_name'])->toBe('Presentation');

    expect($viaRole['name'])->toBe('Base')
        ->and($viaRole['being']['is_main_person'])->toBeFalse()
        ->and($viaRole['being']['same_as_operator'])->toBeTrue();
});

it('leaves instruction text unchanged and reads the main person copy plus group preambles', function () {
    $base = makePerson('Base', 'Address this person as Base.');
    $presentation = makePerson('Presentation', 'Address this person as Presentation.');
    app(KnowledgeIdentityAdmin::class)->bindOperator($base, $this->owner);
    app(PersonaLinks::class)->link($base, $presentation);
    $this->agent->update(['main_person_entry_id' => $presentation->id]);

    $content = 'Always address user as Presentation';
    Instruction::factory()->for($this->agent)->create([
        'content' => $content,
        'layers' => ['Be terse.'],
    ]);
    $alpha = AgentGroup::query()->create(['name' => 'alpha', 'preamble' => 'Alpha line.']);
    $beta = AgentGroup::query()->create(['name' => 'beta', 'preamble' => 'Beta line.']);
    $this->agent->groups()->sync([$beta->id, $alpha->id]);

    $payload = ReadInstructions::payload($this->agent->fresh());

    expect($payload['content'])->toBe($content)
        ->and($payload['layers'])->toBe(['Alpha line.', 'Beta line.', 'Be terse.'])
        ->and($payload['operator'])->toBe('Address this person as Presentation.')
        ->and($payload['identity_context'])->toBe([
            'operator_entry_id' => $base->id,
            'operator_name' => 'Base',
            'main_person_entry_id' => $presentation->id,
            'main_person_name' => 'Presentation',
            'same_being' => true,
        ]);
});

it('inherits one agreed group main person and ignores a disagreement', function () {
    $base = makePerson('Base', 'Base copy.');
    $presentation = makePerson('Presentation', 'Presentation copy.');
    app(KnowledgeIdentityAdmin::class)->bindOperator($base, $this->owner);

    $agreed = AgentGroup::query()->create([
        'name' => 'agreed',
        'preamble' => null,
        'main_person_entry_id' => $presentation->id,
    ]);
    $this->agent->groups()->sync([$agreed->id]);

    $inherited = ReadInstructions::payload($this->agent->fresh());
    expect($inherited['operator'])->toBe('Presentation copy.')
        ->and($inherited['identity_context']['main_person_name'])->toBe('Presentation');

    $other = AgentGroup::query()->create([
        'name' => 'other',
        'main_person_entry_id' => $base->id,
    ]);
    $this->agent->groups()->sync([$agreed->id, $other->id]);

    $split = ReadInstructions::payload($this->agent->fresh());
    expect($split['operator'])->toBe('Base copy.')
        ->and($split['identity_context']['main_person_name'])->toBe('Base');
});

it('lets an agent add an alias and link a persona', function () {
    $project = KnowledgeEntry::factory()->create([
        'name' => 'Harbor',
        'name_lower' => 'harbor',
        'type' => 'project',
        'aliases' => [],
    ]);
    $base = makePerson('Base');
    $presentation = makePerson('Presentation');

    $alias = personaPayload(($this->mcpTool)('knowledge_add_alias', [
        'entry' => 'Harbor',
        'alias' => 'Port',
    ]));
    expect($alias['updated'])->toBeTrue()
        ->and($alias['alias'])->toBe('Port')
        ->and($project->fresh()->aliases)->toBe(['Port']);

    $found = personaPayload(($this->mcpTool)('knowledge_lookup', ['query' => 'Port']));
    expect($found['name'])->toBe('Harbor')
        ->and($found)->not->toHaveKey('being');

    $link = personaPayload(($this->mcpTool)('knowledge_link_persona', [
        'person' => 'Base',
        'persona' => 'Presentation',
    ]));
    expect($link['same_being'])->toBeTrue()
        ->and($link['person'])->toBe('Base')
        ->and($link['persona'])->toBe('Presentation');

    $linked = personaPayload(($this->mcpTool)('knowledge_lookup', ['query' => 'Presentation']));
    expect($linked['name'])->toBe('Presentation')
        ->and($linked['being']['same_as_operator'])->toBeFalse()
        ->and(collect($linked['being']['entries'])->pluck('name')->sort()->values()->all())
        ->toBe(['Base', 'Presentation']);

    $rejected = ($this->mcpTool)('knowledge_link_persona', [
        'person' => 'Harbor',
        'persona' => 'Base',
    ]);
    expect($rejected['isError'] ?? false)->toBeTrue();
});

it('falls back to the legacy operator paragraph when the person has no copy', function () {
    $this->owner->update(['operator_persona' => 'Legacy paragraph.']);

    expect(ReadInstructions::payload($this->agent)['operator'])->toBe('Legacy paragraph.');
});

it('keeps aliases when the edit form omits the aliases field', function () {
    $place = KnowledgeEntry::factory()->create([
        'name' => 'Harbor',
        'name_lower' => 'harbor',
        'type' => 'project',
        'aliases' => [],
        'summary' => 'a dock',
        'state' => '',
        'version' => 1,
    ]);
    app(KnowledgeIdentityAdmin::class)->addIdentifier($place, 'Port', KnowledgeIdentifierKind::Alias);

    $this->actingAs($this->owner)
        ->put(route('console.knowledge.update', $place), [
            'name' => 'Harbor',
            'type' => 'project',
            'summary' => 'a dock',
            'state' => '',
            'expected_version' => $place->fresh()->version,
        ])
        ->assertRedirect();

    expect($place->fresh()->aliases)->toBe(['Port']);
});

it('links a persona from the knowledge page and stores instruction copy on that person', function () {
    $base = makePerson('Base');
    $presentation = makePerson('Presentation');
    app(KnowledgeIdentityAdmin::class)->bindOperator($base, $this->owner->fresh());

    $this->actingAs($this->owner)
        ->get(route('console.dashboard'))
        ->assertOk()
        ->assertDontSee('Rughalt')
        ->assertDontSee('Szefowa')
        ->assertDontSee('Runako')
        ->assertDontSee('Antoni');

    $this->actingAs($this->owner)
        ->get(route('console.knowledge.edit', $base))
        ->assertOk()
        ->assertSee('PERSONAS')
        ->assertSee('INSTRUCTION COPY')
        ->assertDontSee('Rughalt')
        ->assertDontSee('Szefowa');

    $this->actingAs($this->owner)
        ->post(route('console.knowledge.personas.store', $base), [
            'persona_entry_id' => $presentation->id,
        ])
        ->assertRedirect(route('console.knowledge.edit', $base));

    expect(app(PersonaLinks::class)->sameBeing($base, $presentation))->toBeTrue();

    $this->actingAs($this->owner)
        ->put(route('console.knowledge.instruction-copy.store', $base), [
            'instruction_copy' => 'Neutral presenting.',
        ])
        ->assertRedirect(route('console.knowledge.edit', $base));

    expect($base->fresh()->instruction_copy)->toBe('Neutral presenting.')
        ->and($this->owner->fresh()->operator_persona)->toBe('Neutral presenting.');

    $this->actingAs($this->owner)
        ->delete(route('console.knowledge.personas.destroy', [$base, $presentation]))
        ->assertRedirect(route('console.knowledge.edit', $base));

    expect(app(PersonaLinks::class)->sameBeing($base, $presentation))->toBeFalse();
});
