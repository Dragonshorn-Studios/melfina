<?php

use App\Mcp\Tools\WriteFile;
use App\Models\Agent;
use App\Models\Instruction;
use App\Models\VfsFile;

function mcpText(array $result): string
{
    return $result['content'][0]['text'] ?? '';
}

beforeEach(function () {
    $this->agent = Agent::factory()->withKey()->create();

    $this->mcp = function (string $method, array $params = [], ?string $key = 'melf_testkey') {
        $headers = ['Accept' => 'application/json'];

        if ($key !== null) {
            $headers['Authorization'] = 'Bearer '.$key;
        }

        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], $headers);
    };

    $this->mcpTool = function (string $name, array $arguments = [], ?string $key = 'melf_testkey') {
        return ($this->mcp)('tools/call', ['name' => $name, 'arguments' => $arguments], $key)
            ->assertOk()
            ->json('result');
    };
});

it('rejects requests without a bearer key', function () {
    ($this->mcp)('tools/list', [], null)->assertUnauthorized();
});

it('rejects an invalid key', function () {
    ($this->mcp)('tools/list', [], 'melf_wrongkey')->assertUnauthorized();
});

it('rejects a disabled agent key', function () {
    $this->agent->update(['status' => Agent::STATUS_DISABLED]);

    ($this->mcp)('tools/list')->assertUnauthorized();
});

it('lists the four private tools', function () {
    $names = collect(($this->mcp)('tools/list')->assertOk()->json('result.tools'))
        ->pluck('name')->sort()->values();

    expect($names->all())->toBe(['list_files', 'read_file', 'read_instructions', 'write_file']);
});

it('writes then reads a file scoped to the agent', function () {
    $written = ($this->mcpTool)('write_file', ['path' => 'notes/plan.txt', 'content' => 'drift west']);

    expect($written['isError'] ?? false)->toBeFalse();

    $read = ($this->mcpTool)('read_file', ['path' => 'notes/plan.txt']);

    expect(mcpText($read))->toBe('drift west');
});

it('lists only the calling agent\'s paths', function () {
    $other = Agent::factory()->withKey('melf_otherkey')->create();
    VfsFile::writeFor($other, 'other/secret.txt', 'nope');
    ($this->mcpTool)('write_file', ['path' => 'mine.txt', 'content' => 'x']);

    expect(mcpText(($this->mcpTool)('list_files')))->toBe('["mine.txt"]');
});

it('fails closed when another agent\'s path is read', function () {
    $other = Agent::factory()->withKey('melf_otherkey')->create();
    VfsFile::writeFor($other, 'other/secret.txt', 'nope');

    $result = ($this->mcpTool)('read_file', ['path' => 'other/secret.txt']);

    expect($result['isError'])->toBeTrue()
        ->and(mcpText($result))->toContain('not found');
});

it('rejects traversal paths on write', function (string $path) {
    $result = ($this->mcpTool)('write_file', ['path' => $path, 'content' => 'x']);

    expect($result['isError'])->toBeTrue();
})->with(['../escape.txt', 'a/../../b.txt', '..\\win.txt', 'a\\b.txt']);

it('returns empty instructions when none are set', function () {
    expect(json_decode(mcpText(($this->mcpTool)('read_instructions')), true))
        ->toBe(['content' => '', 'layers' => []]);
});

it('returns the agent\'s instruction layers', function () {
    Instruction::factory()->for($this->agent)->create([
        'content' => 'Base rules.',
        'layers' => ['layer one', 'layer two'],
    ]);

    $decoded = json_decode(mcpText(($this->mcpTool)('read_instructions')), true);

    expect($decoded)->toBe(['content' => 'Base rules.', 'layers' => ['layer one', 'layer two']]);
});

it('rate limits writes per agent', function () {
    for ($i = 0; $i < WriteFile::WRITE_LIMIT_PER_MINUTE; $i++) {
        ($this->mcpTool)('write_file', ['path' => 'log.txt', 'content' => (string) $i]);
    }

    $result = ($this->mcpTool)('write_file', ['path' => 'log.txt', 'content' => 'over']);

    expect($result['isError'])->toBeTrue()
        ->and(mcpText($result))->toContain('rate limit');
});
