<?php

namespace App\Models;

use App\Support\FileMime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * One file inside a shared space. Paths use the same normalization rules as
 * the private VFS ({@see VfsFile::normalizePath}).
 *
 * @property string $path
 * @property string $content text content ('' on binary rows)
 * @property string|null $mime non-null marks a binary row
 * @property string|null $content_blob binary bytes (null on text rows)
 */
#[Fillable(['shared_space_id', 'path', 'content', 'mime', 'content_blob'])]
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
     * Read a text file's content scoped to the space, or null when absent
     * — a binary file reads as a miss; fetch it with {@see fileFor()}.
     */
    public static function readFor(SharedSpace $space, string $path): ?string
    {
        $normalized = VfsFile::normalizePath($path);

        return static::query()
            ->where('shared_space_id', $space->id)
            ->where('path', $normalized)
            ->whereNull('mime')
            ->value('content');
    }

    /**
     * Fetch the row itself — the way to reach binary bodies, mime, and
     * timestamps. Null when absent.
     */
    public static function fileFor(SharedSpace $space, string $path): ?self
    {
        $normalized = VfsFile::normalizePath($path);

        return static::query()
            ->where('shared_space_id', $space->id)
            ->where('path', $normalized)
            ->first();
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

        $attributes = ['content' => $content, 'content_blob' => null, 'mime' => null];

        try {
            return static::query()->updateOrCreate(
                ['shared_space_id' => $space->id, 'path' => $normalized],
                $attributes,
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent insert won the row — overwrite it so the last writer wins.
            static::query()
                ->where('shared_space_id', $space->id)
                ->where('path', $normalized)
                ->update($attributes);

            return static::query()
                ->where('shared_space_id', $space->id)
                ->where('path', $normalized)
                ->firstOrFail();
        }
    }

    /**
     * Write (upsert) a binary file scoped to the space — sniffed mime wins
     * over the caller's declaration, same as {@see VfsFile::writeBinaryFor()}.
     *
     * @throws InvalidArgumentException on invalid path or oversized content.
     */
    public static function writeBinaryFor(SharedSpace $space, string $path, string $bytes, ?string $mime = null): self
    {
        $normalized = VfsFile::normalizePath($path);

        if (strlen($bytes) > VfsFile::MAX_BINARY_BYTES) {
            throw new InvalidArgumentException('Content exceeds '.VfsFile::MAX_BINARY_BYTES.' bytes.');
        }

        $mime = FileMime::sniff($bytes)
            ?? FileMime::normalize($mime)
            ?? 'application/octet-stream';

        $attributes = ['content' => '', 'content_blob' => $bytes, 'mime' => $mime];

        try {
            return static::query()->updateOrCreate(
                ['shared_space_id' => $space->id, 'path' => $normalized],
                $attributes,
            );
        } catch (UniqueConstraintViolationException) {
            static::query()
                ->where('shared_space_id', $space->id)
                ->where('path', $normalized)
                ->update($attributes);

            return static::query()
                ->where('shared_space_id', $space->id)
                ->where('path', $normalized)
                ->firstOrFail();
        }
    }

    /**
     * The stored body regardless of kind: text content or blob bytes.
     */
    public function bytes(): string
    {
        return $this->mime === null ? $this->content : (string) $this->content_blob;
    }

    public function isBinary(): bool
    {
        return $this->mime !== null;
    }

    public function byteSize(): int
    {
        return strlen($this->bytes());
    }

    /**
     * Delete a file scoped to the space. Returns whether a row was removed.
     */
    public static function deleteFor(SharedSpace $space, string $path): bool
    {
        $normalized = VfsFile::normalizePath($path);

        return static::query()
            ->where('shared_space_id', $space->id)
            ->where('path', $normalized)
            ->delete() > 0;
    }
}
