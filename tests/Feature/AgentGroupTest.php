<?php

use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\KnowledgeEntry;
use App\Models\SharedFile;
use App\Models\SharedGroupAcl;
use App\Models\SharedSpace;
use App\Models\User;
use App\Support\KnowledgeName;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->withKey()->create(['name' => 'navigator']);
    $this->space = SharedSpace::factory()->create(['name' => 'drop-point']);

    $this->mcpTool = function (string $name, array $arguments = [], ?string $key = 'melf_testkey') {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$key,
        ])->assertOk()->json('result');
    };
});

function groupText(array $result): string
{
    return $result['content'][0]['text'] ?? '';
}

it('lets the owner create a group and put an agent in it', function () {
    $person = KnowledgeEntry::factory()->create([
        'name' => 'Presentation',
        'name_lower' => KnowledgeName::normalize('Presentation'),
        'type' => 'person',
        'aliases' => [],
    ]);

    $this->actingAs($this->owner)
        ->post(route('console.groups.store'), [
            'name' => 'fleet',
            'preamble' => 'Address this person as Presentation.',
            'main_person_entry_id' => $person->id,
            'agents' => [$this->agent->id],
        ])
        ->assertRedirect();

    $group = AgentGroup::query()->where('name', 'fleet')->first();
    expect($group)->not->toBeNull()
        ->and($group->preamble)->toBe('Address this person as Presentation.')
        ->and($group->main_person_entry_id)->toBe($person->id)
        ->and($group->agents()->pluck('agents.id')->all())->toBe([$this->agent->id]);

    $this->actingAs($this->owner)
        ->get(route('console.groups.index'))
        ->assertOk()
        ->assertSee('fleet')
        ->assertSee('Presentation')
        ->assertDontSee('Rughalt')
        ->assertDontSee('Szefowa');

    $this->actingAs($this->owner)
        ->get(route('console.agents.show', $this->agent))
        ->assertOk()
        ->assertSee('GROUPS')
        ->assertSee('fleet')
        ->assertSee('Address this person as Presentation.')
        ->assertSee('Presentation');

    $this->actingAs($this->owner)
        ->put(route('console.agents.main-person.store', $this->agent), [
            'main_person_entry_id' => '',
            'groups' => [$group->id],
        ])
        ->assertRedirect(route('console.agents.show', $this->agent));

    expect($this->agent->fresh()->groups()->pluck('agent_groups.id')->all())->toBe([$group->id]);

    $this->actingAs($this->owner)
        ->put(route('console.agents.main-person.store', $this->agent), [
            'main_person_entry_id' => $person->id,
        ])
        ->assertRedirect(route('console.agents.show', $this->agent));

    expect($this->agent->fresh()->main_person_entry_id)->toBe($person->id)
        ->and($this->agent->fresh()->groups()->pluck('agent_groups.id')->all())->toBe([$group->id]);
});

it('grants a group read or write on a space and its files', function () {
    $group = AgentGroup::query()->create(['name' => 'fleet', 'preamble' => 'Shared line.']);
    $group->agents()->sync([$this->agent->id]);

    $this->actingAs($this->owner)
        ->put(route('console.shared.update', $this->space), [
            'name' => $this->space->name,
            'acl' => [$this->agent->id => 'none'],
            'group_acl' => [$group->id => 'read'],
        ])
        ->assertRedirect();

    expect($this->space->fresh()->permissionFor($this->agent))->toBe('read')
        ->and($this->space->canRead($this->agent))->toBeTrue()
        ->and($this->space->canWrite($this->agent))->toBeFalse()
        ->and(SharedSpace::readableBy($this->agent)->all())->toBe(['drop-point'])
        ->and(SharedGroupAcl::query()->count())->toBe(1);

    $denied = ($this->mcpTool)('write_shared', ['space' => 'drop-point', 'path' => 'note.txt', 'content' => 'nope']);
    expect($denied['isError'])->toBeTrue()
        ->and(SharedFile::count())->toBe(0);

    $this->actingAs($this->owner)
        ->put(route('console.shared.update', $this->space), [
            'name' => $this->space->name,
            'group_acl' => [$group->id => 'write'],
        ]);

    $written = ($this->mcpTool)('write_shared', ['space' => 'drop-point', 'path' => 'note.txt', 'content' => 'bridge']);
    expect($written['isError'] ?? false)->toBeFalse();
    expect(groupText(($this->mcpTool)('read_shared', ['space' => 'drop-point', 'path' => 'note.txt'])))->toBe('bridge');
});

it('lets a direct agent grant win and lets write beat read across groups', function () {
    $readers = AgentGroup::query()->create(['name' => 'readers']);
    $writers = AgentGroup::query()->create(['name' => 'writers']);
    $readers->agents()->sync([$this->agent->id]);
    $writers->agents()->sync([$this->agent->id]);

    SharedGroupAcl::query()->create([
        'shared_space_id' => $this->space->id,
        'agent_group_id' => $readers->id,
        'permission' => 'read',
    ]);
    SharedGroupAcl::query()->create([
        'shared_space_id' => $this->space->id,
        'agent_group_id' => $writers->id,
        'permission' => 'write',
    ]);

    expect($this->space->permissionFor($this->agent))->toBe('write');

    $this->actingAs($this->owner)
        ->put(route('console.shared.update', $this->space), [
            'name' => $this->space->name,
            'acl' => [$this->agent->id => 'read'],
            'group_acl' => [
                $readers->id => 'read',
                $writers->id => 'write',
            ],
        ]);

    expect($this->space->fresh()->permissionFor($this->agent))->toBe('read');

    $this->actingAs($this->owner)
        ->put(route('console.shared.update', $this->space), [
            'name' => $this->space->name,
            'acl' => [$this->agent->id => 'none'],
            'group_acl' => [
                $readers->id => 'none',
                $writers->id => 'none',
            ],
        ]);

    expect($this->space->fresh()->permissionFor($this->agent))->toBeNull()
        ->and(SharedGroupAcl::query()->count())->toBe(0);
});

it('removes group grants when the group is deleted', function () {
    $group = AgentGroup::query()->create(['name' => 'fleet']);
    $group->agents()->sync([$this->agent->id]);
    SharedGroupAcl::query()->create([
        'shared_space_id' => $this->space->id,
        'agent_group_id' => $group->id,
        'permission' => 'read',
    ]);

    $this->actingAs($this->owner)
        ->delete(route('console.groups.destroy', $group))
        ->assertRedirect(route('console.groups.index'));

    expect(AgentGroup::query()->count())->toBe(0)
        ->and(SharedGroupAcl::query()->count())->toBe(0)
        ->and($this->space->fresh()->permissionFor($this->agent))->toBeNull();
});

it('blocks a non-owner from group screens', function () {
    $guest = User::factory()->create(['is_owner' => false]);

    $this->actingAs($guest)
        ->get(route('console.groups.index'))
        ->assertForbidden();
});
