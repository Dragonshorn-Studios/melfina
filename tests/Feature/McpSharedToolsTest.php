<?php

use App\Mcp\Tools\WriteFile;
use App\Models\Agent;
use App\Models\SharedAcl;
use App\Models\SharedFile;
use App\Models\SharedSpace;
use App\Models\VfsFile;

beforeEach(function () {
    $this->agent = Agent::factory()->withKey()->create();
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

    $this->grant = function (string $permission, ?Agent $agent = null, ?SharedSpace $space = null) {
        SharedAcl::factory()->for($space ?? $this->space, 'sharedSpace')
            ->for($agent ?? $this->agent)
            ->create(['permission' => $permission]);
    };
});

function sharedText(array $result): string
{
    return $result['content'][0]['text'] ?? '';
}

it('denies list_shared paths without a grant', function () {
    $result = ($this->mcpTool)('list_shared', ['space' => 'drop-point']);

    expect($result['isError'])->toBeTrue()
        ->and(sharedText($result))->toContain('not found');
});

it('denies read_shared without a grant', function () {
    SharedFile::writeFor($this->space, 'note.txt', 'hello');

    $result = ($this->mcpTool)('read_shared', ['space' => 'drop-point', 'path' => 'note.txt']);

    expect($result['isError'])->toBeTrue();
});

it('denies write_shared with only a read grant', function () {
    ($this->grant)('read');

    $result = ($this->mcpTool)('write_shared', ['space' => 'drop-point', 'path' => 'x.txt', 'content' => 'x']);

    expect($result['isError'])->toBeTrue()
        ->and(SharedFile::count())->toBe(0);
});

it('lists only spaces the agent can read', function () {
    ($this->grant)('read');
    SharedSpace::factory()->create(['name' => 'private-club']);

    expect(sharedText(($this->mcpTool)('list_shared')))->toBe('["drop-point"]');
});

it('lists paths inside a granted space', function () {
    ($this->grant)('read');
    SharedFile::writeFor($this->space, 'b.txt', '2');
    SharedFile::writeFor($this->space, 'a.txt', '1');

    expect(sharedText(($this->mcpTool)('list_shared', ['space' => 'drop-point'])))->toBe('["a.txt","b.txt"]');
});

it('reads and writes through a write grant', function () {
    ($this->grant)('write');

    $written = ($this->mcpTool)('write_shared', ['space' => 'drop-point', 'path' => 'share/me.txt', 'content' => 'bridge']);

    expect($written['isError'] ?? false)->toBeFalse();

    $read = ($this->mcpTool)('read_shared', ['space' => 'drop-point', 'path' => 'share/me.txt']);

    expect(sharedText($read))->toBe('bridge');
});

it('lets a second agent with a read grant see the file', function () {
    ($this->grant)('write');
    $other = Agent::factory()->withKey('melf_otherkey')->create();
    ($this->grant)('read', $other);

    ($this->mcpTool)('write_shared', ['space' => 'drop-point', 'path' => 'memo.txt', 'content' => 'to all']);

    $read = ($this->mcpTool)('read_shared', ['space' => 'drop-point', 'path' => 'memo.txt'], 'melf_otherkey');

    expect(sharedText($read))->toBe('to all');
});

it('cannot reach private VFS files through shared tools', function () {
    ($this->grant)('read');
    VfsFile::writeFor($this->agent, 'private-secret.txt', 'mine only');
    VfsFile::writeFor($this->agent, 'shared/memo.txt', 'impostor');

    $result = ($this->mcpTool)('read_shared', ['space' => 'drop-point', 'path' => 'private-secret.txt']);

    expect($result['isError'])->toBeTrue()
        ->and(sharedText(($this->mcpTool)('list_shared', ['space' => 'drop-point'])))->toBe('[]');
});

it('rejects traversal on shared writes', function (string $path) {
    ($this->grant)('write');

    $result = ($this->mcpTool)('write_shared', ['space' => 'drop-point', 'path' => $path, 'content' => 'x']);

    expect($result['isError'])->toBeTrue();
})->with(['../escape.txt', 'a/../../b.txt', '..\\win.txt']);

it('rate limits shared writes per agent', function () {
    ($this->grant)('write');

    for ($i = 0; $i < WriteFile::WRITE_LIMIT_PER_MINUTE; $i++) {
        ($this->mcpTool)('write_shared', ['space' => 'drop-point', 'path' => 'log.txt', 'content' => (string) $i]);
    }

    $result = ($this->mcpTool)('write_shared', ['space' => 'drop-point', 'path' => 'log.txt', 'content' => 'over']);

    expect($result['isError'])->toBeTrue()
        ->and(sharedText($result))->toContain('rate limit');
});

it('does not count invalid writes against the rate limit', function () {
    ($this->grant)('write');

    for ($i = 0; $i < WriteFile::WRITE_LIMIT_PER_MINUTE; $i++) {
        ($this->mcpTool)('write_shared', ['space' => 'drop-point', 'path' => '../evil.txt', 'content' => 'x']);
    }

    $result = ($this->mcpTool)('write_shared', ['space' => 'drop-point', 'path' => 'ok.txt', 'content' => 'x']);

    expect($result['isError'] ?? false)->toBeFalse();
});
