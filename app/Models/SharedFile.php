<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * One file inside a shared space. Paths use the same normalization rules as
 * the private VFS ({@see VfsFile::normalizePath}).
 *
 * @property string $path
 * @property string $content
 */
#[Fillable(['shared_space_id', 'path', 'content'])]
class SharedFile extends Model
{
    use HasFactory;

    public function sharedSpace(): BelongsTo
    {
        return $this->belongsTo(SharedSpace::class);
    }

    /**
     * All normalized paths in the space, sorted.
     *
     * @return Collection<int, string>
     */
    public static function listFor(SharedSpace $space): Collection
    {
        return static::query()
            ->where('shared_space_id', $space->id)
            ->orderBy('path')
            ->pluck('path');
    }

    /**
     * Read a file's text content scoped to the space, or null when absent.
     */
    public static function readFor(SharedSpace $space, string $path): ?string
    {
        $normalized = VfsFile::normalizePath($path);

        return static::query()
            ->where('shared_space_id', $space->id)
            ->where('path', $normalized)
            ->value('content');
    }

    /**
     * Write (upsert) a text file scoped to the space.
     *
     * @throws InvalidArgumentException on invalid path or oversized content.
     */
    public static function writeFor(SharedSpace $space, string $path, string $content): self
    {
        $normalized = VfsFile::normalizePath($path);

        if (strlen($content) > VfsFile::MAX_CONTENT_BYTES) {
            throw new InvalidArgumentException('Content exceeds '.VfsFile::MAX_CONTENT_BYTES.' bytes.');
        }

        return static::query()->updateOrCreate(
            ['shared_space_id' => $space->id, 'path' => $normalized],
            ['content' => $content],
        );
    }
}
