<?php

use App\Mcp\Tools\KnowledgeUpsert;
use App\Models\Agent;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeLink;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

function knowledgeResult(array $result): array
{
    return json_decode($result['content'][0]['text'] ?? '', true);
}

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->agent = Agent::factory()->withKey()->create(['name' => 'grokbot']);

    $this->mcpTool = function (string $tool, array $args = [], ?string $bearer = null) {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $args],
        ], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.($bearer ?? $this->key)]);

        return $response->json('result');
    };

    $this->key = 'melf_testkey';
});

it('creates an entry and finds it by canonical name, case, and alias', function () {
    $created = knowledgeResult(($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Yukine',
        'type' => 'project',
        'aliases' => ['yuki', 'snowfox'],
        'summary' => 'Discord bridge for the house bots',
        'state' => 'Runs on the home box; SQLite store.',
        'source' => 'owner chat 2026-09-20',
    ]));

    expect($created['updated'])->toBeTrue()
        ->and($created['created'])->toBeTrue()
        ->and($created['version'])->toBe(1);

    foreach (['Yukine', 'yukine', 'yuki', 'SNOWFOX'] as $needle) {
        $found = knowledgeResult(($this->mcpTool)('knowledge_lookup', ['query' => $needle]));
        expect($found['found'])->toBeTrue()
            ->and($found['name'])->toBe('Yukine')
            ->and($found['type'])->toBe('project')
            ->and($found['updated_by'])->toBe('grokbot')
            ->and($found['source'])->toBe('owner chat 2026-09-20');
    }
});

it('says plainly when nothing internal matches', function () {
    $miss = knowledgeResult(($this->mcpTool)('knowledge_lookup', ['query' => 'nonexistent']));

    expect($miss['found'])->toBeFalse()
        ->and($miss['hint'])->toContain('external');
});

it('resolves related entries and reports stale ones', function () {
    KnowledgeEntry::factory()->create(['name' => 'Melfina', 'name_lower' => 'melfina', 'summary' => 'Imp brain']);
    KnowledgeEntry::factory()->create([
        'name' => 'Shiori',
        'name_lower' => 'shiori',
        'related' => ['Melfina', 'Gone Project'],
    ]);

    $found = knowledgeResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Shiori']));

    expect($found['related'])->toHaveCount(1)
        ->and($found['related'][0]['name'])->toBe('Melfina')
        ->and($found['related'][0]['summary'])->toBe('Imp brain')
        ->and($found['unresolved_related'])->toBe(['Gone Project']);
});

it('returns version history on demand', function () {
    ($this->mcpTool)('knowledge_upsert', ['entry' => 'Yukine', 'type' => 'project', 'summary' => 'v1']);
    ($this->mcpTool)('knowledge_upsert', ['entry' => 'Yukine', 'summary' => 'v2']);

    $found = knowledgeResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Yukine', 'history' => true]));

    expect($found['version'])->toBe(2)
        ->and($found['history'])->toHaveCount(2)
        ->and($found['history'][0]['version'])->toBe(2)
        ->and($found['history'][1]['version'])->toBe(1)
        ->and($found['history'][0]['author'])->toBe('grokbot');

    // History is opt-in — without the flag the payload stays lean.
    $lean = knowledgeResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Yukine']));
    expect($lean)->not->toHaveKey('history');
});

it('searches fuzzily with name hits ranked over body hits', function () {
    KnowledgeEntry::factory()->create([
        'name' => 'Yukine', 'name_lower' => 'yukine', 'summary' => 'Discord bridge',
    ]);
    KnowledgeEntry::factory()->create([
        'name' => 'Melfina', 'name_lower' => 'melfina', 'state' => 'deploys via yukine-adjacent infra',
    ]);
    KnowledgeEntry::factory()->create([
        'name' => 'Botbox', 'name_lower' => 'botbox', 'aliases' => ['yukine host'],
        'summary' => 'home server',
    ]);

    $found = knowledgeResult(($this->mcpTool)('knowledge_search', ['query' => 'yukine']));

    expect($found['found'])->toBeTrue();
    $names = collect($found['results'])->pluck('name')->all();
    expect($names[0])->toBe('Yukine')
        ->and($names)->toContain('Melfina', 'Botbox');
});

it('scopes search by type and caps the limit', function () {
    KnowledgeEntry::factory()->create(['name' => 'Yukine', 'name_lower' => 'yukine', 'type' => 'project']);
    KnowledgeEntry::factory()->create(['name' => 'yukine bot', 'name_lower' => 'yukine bot', 'type' => 'service']);

    $onlyServices = knowledgeResult(($this->mcpTool)('knowledge_search', ['query' => 'yukine', 'type' => 'service']));
    expect(collect($onlyServices['results'])->pluck('name')->all())->toBe(['yukine bot']);
});

it('admits external lookup when internal search is empty', function () {
    $miss = knowledgeResult(($this->mcpTool)('knowledge_search', ['query' => 'quantum toaster']));

    expect($miss['found'])->toBeFalse()
        ->and($miss['hint'])->toContain('external');
});

it('requires a type for new entries but not for updates', function () {
    $noType = ($this->mcpTool)('knowledge_upsert', ['entry' => 'Ortolan', 'summary' => 'x']);
    expect($noType['isError'] ?? false)->toBeTrue();
    expect(KnowledgeEntry::query()->where('name_lower', 'ortolan')->exists())->toBeFalse();

    ($this->mcpTool)('knowledge_upsert', ['entry' => 'Ortolan', 'type' => 'concept']);
    $update = knowledgeResult(($this->mcpTool)('knowledge_upsert', ['entry' => 'ortolan', 'summary' => 'refined']));

    expect($update['updated'])->toBeTrue()
        ->and($update['created'])->toBeFalse()
        ->and($update['version'])->toBe(2);
    expect(KnowledgeEntry::query()->count())->toBe(1);
});

it('fails optimistic writes on a stale expected_version', function () {
    ($this->mcpTool)('knowledge_upsert', ['entry' => 'Yukine', 'type' => 'project']);

    $conflict = knowledgeResult(($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Yukine',
        'summary' => 'stale write',
        'expected_version' => 7,
    ]));

    expect($conflict['conflict'])->toBeTrue()
        ->and($conflict['current_version'])->toBe(1);
    expect(KnowledgeEntry::first()->summary)->not->toBe('stale write');

    $ok = knowledgeResult(($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Yukine',
        'summary' => 'fresh write',
        'expected_version' => 1,
    ]));
    expect($ok['updated'])->toBeTrue();
});

it('keeps knowledge shared across agents and records who wrote', function () {
    $other = Agent::factory()->withKey('melf_otherkey')->create(['name' => 'kuchcik']);

    ($this->mcpTool)('knowledge_upsert', ['entry' => 'Yukine', 'type' => 'project']);
    ($this->mcpTool)('knowledge_upsert', ['entry' => 'Yukine', 'summary' => 'edited by kuchcik'], 'melf_otherkey');

    $entry = KnowledgeEntry::first();
    expect($entry->updated_by)->toBe('kuchcik')
        ->and($entry->versions()->count())->toBe(2)
        ->and($entry->versions()->orderBy('version')->pluck('author')->all())->toBe(['grokbot', 'kuchcik']);
});

it('works over the fleet key when the call names the agent', function () {
    $fleetKey = $this->owner->rotateMcpKey();

    $found = knowledgeResult(($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Yukine', 'type' => 'project', 'name' => 'grokbot',
    ], $fleetKey));

    // `name` in arguments is the persona on a fleet connection; `entry` is
    // the knowledge entry's canonical name — they must not collide.
    expect($found['updated'])->toBeTrue();
    expect(KnowledgeEntry::first()->updated_by)->toBe('grokbot');
});

it('connects two entities and returns the typed neighborhood on lookup', function () {
    ($this->mcpTool)('knowledge_upsert', ['entry' => 'Yukine', 'type' => 'project', 'summary' => 'Discord bridge']);
    ($this->mcpTool)('knowledge_upsert', ['entry' => 'Botbox', 'type' => 'service', 'summary' => 'home server']);

    $connected = knowledgeResult(($this->mcpTool)('knowledge_connect', [
        'from' => 'Yukine',
        'to' => 'Botbox',
        'predicate' => 'runs_on',
        'note' => 'deploy log',
        'data' => ['version' => '1.2'],
    ]));

    expect($connected['updated'])->toBeTrue()
        ->and($connected['predicate'])->toBe('runs_on')
        ->and($connected['confidence'])->toBe(220);

    $found = knowledgeResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Yukine']));
    expect($found['links']['outgoing'])->toHaveCount(1)
        ->and($found['links']['outgoing'][0]['predicate'])->toBe('runs_on')
        ->and($found['links']['outgoing'][0]['name'])->toBe('Botbox')
        ->and($found['links']['outgoing'][0]['confidence'])->toBe(220);

    $inverse = knowledgeResult(($this->mcpTool)('knowledge_lookup', ['query' => 'Botbox']));
    expect($inverse['links']['incoming'][0]['predicate'])->toBe('hosts');
});

it('refuses to connect an unknown endpoint without create_missing', function () {
    ($this->mcpTool)('knowledge_upsert', ['entry' => 'Yukine', 'type' => 'project']);

    $miss = knowledgeResult(($this->mcpTool)('knowledge_connect', [
        'from' => 'Yukine',
        'to' => 'ghost-host',
        'predicate' => 'runs_on',
    ]));

    expect($miss['updated'])->toBeFalse()
        ->and($miss['unresolved']['to'])->toBe('ghost-host');
});

it('charges related dual-writes against the knowledge write budget', function () {
    KnowledgeEntry::factory()->create(['name' => 'Melfina', 'name_lower' => 'melfina']);
    RateLimiter::increment('mcp-knowledge:'.$this->agent->id, 60, KnowledgeUpsert::WRITE_LIMIT_PER_MINUTE - 1);

    $blocked = ($this->mcpTool)('knowledge_upsert', [
        'entry' => 'Shiori',
        'type' => 'project',
        'related' => ['Melfina'],
    ]);

    expect($blocked['isError'] ?? false)->toBeTrue()
        ->and($blocked['content'][0]['text'])->toContain('rate limit');
    expect(KnowledgeEntry::query()->where('name_lower', 'shiori')->exists())->toBeFalse()
        ->and(KnowledgeLink::query()->count())->toBe(0);
});

it('charges named-event side links against the knowledge write budget', function () {
    KnowledgeEntry::factory()->create(['name' => 'Yukine', 'name_lower' => 'yukine', 'type' => 'project']);
    KnowledgeEntry::factory()->create(['name' => 'Botbox', 'name_lower' => 'botbox', 'type' => 'service']);
    RateLimiter::increment('mcp-knowledge:'.$this->agent->id, 60, KnowledgeUpsert::WRITE_LIMIT_PER_MINUTE - 2);

    $blocked = ($this->mcpTool)('knowledge_connect', [
        'from' => 'Yukine',
        'to' => 'Botbox',
        'predicate' => 'runs_on',
        'event' => 'Yukine deploy 1.2',
    ]);

    expect($blocked['isError'] ?? false)->toBeTrue()
        ->and($blocked['content'][0]['text'])->toContain('rate limit');
    expect(KnowledgeLink::query()->count())->toBe(0)
        ->and(KnowledgeEntry::query()->where('name_lower', 'yukine deploy 1.2')->exists())->toBeFalse();
});
