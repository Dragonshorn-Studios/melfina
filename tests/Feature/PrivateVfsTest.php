<?php

use App\Models\Agent;
use App\Models\VfsFile;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->agent = Agent::factory()->create();
});

it('writes, reads, and lists files scoped to the agent', function () {
    VfsFile::writeFor($this->agent, 'notes/plan.txt', 'drift west');
    VfsFile::writeFor($this->agent, 'logs/boot.txt', 'online');

    expect(VfsFile::readFor($this->agent, 'notes/plan.txt'))->toBe('drift west')
        ->and(VfsFile::listFor($this->agent)->all())->toBe(['logs/boot.txt', 'notes/plan.txt']);
});

it('overwrites the same path instead of duplicating it', function () {
    VfsFile::writeFor($this->agent, 'a.txt', 'one');
    VfsFile::writeFor($this->agent, 'a.txt', 'two');

    expect(VfsFile::count())->toBe(1)
        ->and(VfsFile::readFor($this->agent, 'a.txt'))->toBe('two');
});

it('normalizes redundant separators, dots, and leading slashes', function (string $raw, string $normalized) {
    expect(VfsFile::normalizePath($raw))->toBe($normalized);
})->with([
    ['notes/plan.txt', 'notes/plan.txt'],
    ['/notes/plan.txt', 'notes/plan.txt'],
    ['notes//plan.txt', 'notes/plan.txt'],
    ['notes/./plan.txt', 'notes/plan.txt'],
    ['./notes/plan.txt', 'notes/plan.txt'],
    ['notes/plan.txt/', 'notes/plan.txt'],
]);

it('rejects traversal, backslashes, control chars, and empty paths', function (string $raw) {
    VfsFile::writeFor($this->agent, $raw, 'x');
})->with([
    '../secret',
    'a/../../b',
    '..',
    'a/../..',
    '..\\secret',
    'a\\b.txt',
    "bad\x01path.txt",
    '',
    '/',
    '.',
    './',
    '   ',
])->throws(InvalidArgumentException::class);

it('rejects over-length paths and content', function () {
    VfsFile::writeFor($this->agent, str_repeat('a', 513), 'x');
})->throws(InvalidArgumentException::class);

it('rejects oversized content', function () {
    VfsFile::writeFor($this->agent, 'big.txt', str_repeat('x', VfsFile::MAX_CONTENT_BYTES + 1));
})->throws(InvalidArgumentException::class);

it('isolates agents — A cannot see B paths', function () {
    $other = Agent::factory()->create();
    VfsFile::writeFor($other, 'secret.txt', 'belongs to B');

    expect(VfsFile::listFor($this->agent)->all())->toBe([])
        ->and(VfsFile::readFor($this->agent, 'secret.txt'))->toBeNull();
});

it('reads return null for missing paths, not errors', function () {
    expect(VfsFile::readFor($this->agent, 'missing.txt'))->toBeNull();
});

it('enforces the unique(agent_id,path) constraint at the DB level', function () {
    VfsFile::factory()->for($this->agent)->create(['path' => 'dup.txt']);
    VfsFile::factory()->for($this->agent)->create(['path' => 'dup.txt']);
})->throws(QueryException::class);
