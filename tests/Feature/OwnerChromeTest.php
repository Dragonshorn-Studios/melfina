<?php

use App\Models\Agent;
use App\Models\Instruction;
use App\Models\SharedSpace;
use App\Models\User;
use App\Models\VfsFile;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->create();
});

it('renders the sidebar with agents and section links', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.index'))
        ->assertOk()
        ->assertSee($this->agent->name)
        ->assertSee(route('console.agents.show', $this->agent))
        ->assertSee(route('console.shared.index'))
        ->assertSee(route('console.clients.index'))
        ->assertSee(route('console.agents.create'));
});

it('folds the global files and instructions indexes into the agent list', function () {
    $this->actingAs($this->owner)
        ->get(route('console.files.index'))
        ->assertRedirect(route('console.agents.index'));

    $this->actingAs($this->owner)
        ->get(route('console.instructions.index'))
        ->assertRedirect(route('console.agents.index'));
});

it('renders the per-agent tab strip on agent pages', function () {
    $this->actingAs($this->owner)
        ->get(route('console.files.show', $this->agent))
        ->assertOk()
        ->assertSee(route('console.files.show', $this->agent))
        ->assertSee(route('console.agents.instructions.edit', $this->agent))
        ->assertSee(route('console.agents.persona.edit', $this->agent))
        ->assertSee(route('console.agents.show', $this->agent))
        ->assertSee(route('console.agents.connect', $this->agent));
});

it('renders the connect guides for an agent', function () {
    $this->actingAs($this->owner)
        ->get(route('console.agents.connect', $this->agent))
        ->assertOk()
        ->assertSee(url('/mcp'))
        ->assertSee('claude mcp add --transport http melfina', false)
        ->assertSee('mcp-remote')
        ->assertSee('MCP_REDIRECT_DOMAINS')
        ->assertSee('~/.mavis/mcp/mcp.json')
        ->assertSee('Bearer melf_…', false);
});

it('shows fleet stats and section links on the dashboard', function () {
    VfsFile::writeFor($this->agent, 'a.txt', '1');
    $this->agent->rotateKey();
    $space = SharedSpace::factory()->create();
    $space->aclEntries()->create(['agent_id' => $this->agent->id, 'permission' => 'write']);

    $this->actingAs($this->owner)
        ->get(route('console.dashboard'))
        ->assertOk()
        ->assertSee('Fleet at a glance')
        ->assertSee($this->agent->name)
        ->assertSee(route('console.agents.show', $this->agent))
        ->assertSee(route('console.agents.index'))
        ->assertSee(route('console.shared.index'))
        ->assertSee(route('console.agents.create'));

    // The roster table — with file counts, key state, last write — lives on the index.
    $this->actingAs($this->owner)
        ->get(route('console.agents.index'))
        ->assertOk()
        ->assertSee($this->agent->name)
        ->assertSee('issued', false);
});

it('shows agent file counts in the sidebar', function () {
    VfsFile::writeFor($this->agent, 'a.txt', '1');
    VfsFile::writeFor($this->agent, 'b.txt', '2');

    $this->actingAs($this->owner)
        ->get(route('console.agents.index'))
        ->assertOk()
        ->assertSee($this->agent->name)
        ->assertSee('text-steel">2<', false);
});

it('lets the owner browse, write, and delete files in an agent tree', function () {
    VfsFile::writeFor($this->agent, 'notes/plan.txt', 'original');

    $this->actingAs($this->owner)
        ->get(route('console.files.show', $this->agent))
        ->assertOk()
        ->assertSee('notes/');

    // The tree browses per directory — descend to see the file itself.
    $this->actingAs($this->owner)
        ->get(route('console.files.show', ['agent' => $this->agent, 'dir' => 'notes/']))
        ->assertOk()
        ->assertSee('plan.txt');

    $this->actingAs($this->owner)
        ->put(route('console.files.update', $this->agent), [
            'path' => 'notes/plan.txt',
            'content' => 'edited',
        ])->assertRedirect(route('console.files.show', ['agent' => $this->agent, 'dir' => 'notes/']));

    expect(VfsFile::readFor($this->agent, 'notes/plan.txt'))->toBe('edited');

    $this->actingAs($this->owner)
        ->put(route('console.files.update', $this->agent), [
            'path' => 'fresh/new.txt',
            'content' => 'seeded',
        ]);

    expect(VfsFile::readFor($this->agent, 'fresh/new.txt'))->toBe('seeded');

    $this->actingAs($this->owner)
        ->delete(route('console.files.destroy', $this->agent), [
            'path' => 'fresh/new.txt',
        ]);

    expect(VfsFile::readFor($this->agent, 'fresh/new.txt'))->toBeNull();
});

it('rejects invalid paths on file write', function () {
    $this->actingAs($this->owner)
        ->put(route('console.files.update', $this->agent), [
            'path' => '../escape.txt',
            'content' => 'x',
        ])->assertSessionHasErrors('path');
});

it('shows the per-agent instruction editor', function () {
    Instruction::factory()->for($this->agent)->create(['content' => 'rules']);

    $this->actingAs($this->owner)
        ->get(route('console.agents.instructions.edit', $this->agent))
        ->assertOk()
        ->assertSee('rules');
});

it('lets the owner read and curate an agent persona and memories', function () {
    $this->agent->update(['persona' => 'gruff and precise']);
    $this->agent->remember('owner prefers terse replies');
    $this->agent->remember('prod host is melfina.dragonshorn.cloud');

    $this->actingAs($this->owner)
        ->get(route('console.agents.persona.edit', $this->agent))
        ->assertOk()
        ->assertSee('gruff and precise')
        ->assertSee('owner prefers terse replies')
        ->assertSee('prod host is melfina.dragonshorn.cloud');

    // The About page links to the persona tab and shows an excerpt.
    $this->actingAs($this->owner)
        ->get(route('console.agents.show', $this->agent))
        ->assertOk()
        ->assertSee(route('console.agents.persona.edit', $this->agent))
        ->assertSee('gruff and precise');

    // Persona rewrite + owner-appended memory in one submit.
    $this->actingAs($this->owner)
        ->put(route('console.agents.persona.update', $this->agent), [
            'persona' => 'gentle and verbose',
            'remember' => 'owner works from Warsaw',
        ])->assertRedirect(route('console.agents.persona.edit', $this->agent));

    $fresh = $this->agent->fresh();
    expect($fresh->persona)->toBe('gentle and verbose')
        ->and(collect($fresh->memories)->pluck('text'))
        ->toContain('owner works from Warsaw');

    // A bare remember submit leaves the persona alone.
    $this->actingAs($this->owner)
        ->put(route('console.agents.persona.update', $this->agent), [
            'remember' => 'persona untouched',
        ])->assertRedirect();
    expect($this->agent->fresh()->persona)->toBe('gentle and verbose');

    // Pruning by index re-packs the log — the form echoes a hash of the
    // entry it rendered so a stale page can't silently prune the wrong one.
    $expect = md5(json_encode($this->agent->fresh()->memories[0]));
    $this->actingAs($this->owner)
        ->delete(route('console.agents.memories.destroy', [$this->agent, 0]), ['expect' => $expect])
        ->assertRedirect(route('console.agents.persona.edit', $this->agent));

    expect(collect($this->agent->fresh()->memories)->pluck('text')->all())
        ->toEqual(['prod host is melfina.dragonshorn.cloud', 'owner works from Warsaw', 'persona untouched']);

    // A mismatched expect (stale index after a shift) is a 404, not a
    // wrong-entry delete.
    $this->actingAs($this->owner)
        ->delete(route('console.agents.memories.destroy', [$this->agent, 0]), ['expect' => $expect])
        ->assertNotFound();
    expect($this->agent->fresh()->memories)->toHaveCount(3);

    $this->actingAs($this->owner)
        ->delete(route('console.agents.memories.destroy', [$this->agent, 99]))
        ->assertNotFound();

    // A non-numeric index is a 404, not a 500 from route-model binding.
    $this->actingAs($this->owner)
        ->delete('/console/agents/'.$this->agent->id.'/memories/abc')
        ->assertNotFound();
});

it('enforces the same field limits as the update_persona tool', function () {
    $this->actingAs($this->owner)
        ->put(route('console.agents.persona.update', $this->agent), [
            'persona' => str_repeat('x', 20001),
        ])->assertSessionHasErrors('persona');

    $this->actingAs($this->owner)
        ->put(route('console.agents.persona.update', $this->agent), [
            'remember' => str_repeat('x', 2001),
        ])->assertSessionHasErrors('remember');

    expect($this->agent->fresh()->persona)->toBeNull()
        ->and($this->agent->fresh()->memories)->toBeNull();
});

it('renders persona and memory text escaped', function () {
    $this->agent->update(['persona' => '<script>alert(1)</script>']);
    $this->agent->remember('<img src=x onerror=alert(1)>');

    $this->actingAs($this->owner)
        ->get(route('console.agents.persona.edit', $this->agent))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('redirects guests from the persona editor to login', function () {
    $this->get(route('console.agents.persona.edit', $this->agent))->assertRedirect(route('login'));
    $this->put(route('console.agents.persona.update', $this->agent), ['persona' => 'x'])->assertRedirect(route('login'));
    $this->delete(route('console.agents.memories.destroy', [$this->agent, 0]))->assertRedirect(route('login'));
});

it('keeps non-owners off the persona editor', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('console.agents.persona.edit', $this->agent))->assertForbidden();
    $this->actingAs($user)->put(route('console.agents.persona.update', $this->agent), [
        'persona' => 'x',
    ])->assertForbidden();
    $this->actingAs($user)
        ->delete(route('console.agents.memories.destroy', [$this->agent, 0]))
        ->assertForbidden();
});

it('renders the dismiss control on a flashed key', function () {
    $this->actingAs($this->owner)
        ->withSession(['plaintext_key' => 'melf_secret'])
        ->get(route('console.agents.show', $this->agent))
        ->assertOk()
        ->assertSee('melf_secret')
        ->assertSee('dismiss');
});

it('gates every destructive form behind the shared confirm dialog', function () {
    $gate = 'onsubmit="return window.__melfinaConfirm';

    $this->actingAs($this->owner)
        ->get(route('console.agents.show', $this->agent))
        ->assertOk()
        ->assertSee('data-confirm="Rotate key?', false)
        ->assertSee($gate, false);

    VfsFile::writeFor($this->agent, 'notes/plan.txt', 'x');
    $this->actingAs($this->owner)
        ->get(route('console.files.edit', [$this->agent, 'path' => 'notes/plan.txt']))
        ->assertOk()
        ->assertSee('data-confirm="Delete notes/plan.txt?', false)
        ->assertSee($gate, false);

    $this->postJson('/oauth/register', [
        'client_name' => 'Gatecheck',
        'redirect_uris' => ['http://localhost/callback'],
    ])->assertCreated();

    $this->actingAs($this->owner)
        ->get(route('console.clients.index'))
        ->assertOk()
        ->assertSee('data-confirm="Revoke Gatecheck?', false)
        ->assertSee($gate, false);
});

it('blocks non-owners from files and instructions routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('console.files.index'))->assertForbidden();
    $this->actingAs($user)->get(route('console.files.show', $this->agent))->assertForbidden();
    $this->actingAs($user)->put(route('console.files.update', $this->agent), [
        'path' => 'x', 'content' => 'x',
    ])->assertForbidden();
    $this->actingAs($user)->get(route('console.instructions.index'))->assertForbidden();
});
