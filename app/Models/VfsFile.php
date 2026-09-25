<?php

namespace App\Models;

use Database\Factories\VfsFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * One private VFS entry for an agent — the `files` table is the single
 * source of truth. Paths are stored normalized (relative, `/`-separated).
 *
 * @property string $path
 * @property string $content
 */
#[Fillable(['agent_id', 'path', 'content'])]
class VfsFile extends Model
{
    /** @use HasFactory<VfsFileFactory> */
    use HasFactory;

    public const MAX_PATH_LENGTH = 512;

    public const MAX_CONTENT_BYTES = 1048576;

    protected $table = 'files';

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Canonicalize a VFS path: `/`-separated, no leading slash, `.` removed.
     *
     * @throws InvalidArgumentException on traversal (`..`, `..\`), backslashes,
     *                                  control/NUL bytes, empty result, or over-length paths.
     */
    public static function normalizePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid path.');
        }

        if (str_contains($path, '\\')) {
            throw new InvalidArgumentException('Backslashes are not allowed in paths.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new InvalidArgumentException('Control characters are not allowed in paths.');
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException('Path traversal is not allowed.');
            }
            $segments[] = $segment;
        }

        $normalized = implode('/', $segments);

        if ($normalized === '') {
            throw new InvalidArgumentException('Invalid path.');
        }

        if (mb_strlen($normalized) > self::MAX_PATH_LENGTH) {
            throw new InvalidArgumentException('Path exceeds '.self::MAX_PATH_LENGTH.' characters.');
        }

        return $normalized;
    }

    /**
     * All normalized paths belonging to the agent, sorted.
     *
     * @return Collection<int, string>
     */
    public static function listFor(Agent $agent): Collection
    {
        return static::query()
            ->where('agent_id', $agent->id)
            ->orderBy('path')
            ->pluck('path');
    }

    /**
     * Read a file's text content scoped to the agent, or null when absent.
     * Never reaches across agents.
     */
    public static function readFor(Agent $agent, string $path): ?string
    {
        $normalized = static::normalizePath($path);

        return static::query()
            ->where('agent_id', $agent->id)
            ->where('path', $normalized)
            ->value('content');
    }

    /**
     * Write (upsert) a text file scoped to the agent.
     *
     * @throws InvalidArgumentException on invalid path or oversized content.
     */
    public static function writeFor(Agent $agent, string $path, string $content): self
    {
        $normalized = static::normalizePath($path);

        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new InvalidArgumentException('Content exceeds '.self::MAX_CONTENT_BYTES.' bytes.');
        }

        return static::query()->updateOrCreate(
            ['agent_id' => $agent->id, 'path' => $normalized],
            ['content' => $content],
        );
    }

    /**
     * Delete a file scoped to the agent. Returns whether a row was removed.
     */
    public static function deleteFor(Agent $agent, string $path): bool
    {
        $normalized = static::normalizePath($path);

        return static::query()
            ->where('agent_id', $agent->id)
            ->where('path', $normalized)
            ->delete() > 0;
    }
}
