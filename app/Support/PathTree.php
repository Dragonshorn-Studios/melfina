<?php

namespace App\Support;

use App\Models\VfsFile;
use InvalidArgumentException;

/**
 * Turns a flat list of normalized VFS/shared paths into one browsable
 * directory level: the console's agent and shared file lists navigate
 * `?dir=` instead of rendering every path at once.
 */
final class PathTree
{
    /**
     * Normalize a directory prefix to '' or 'a/b/' form. Invalid input
     * (traversal, backslashes, control bytes) falls back to the root
     * listing — a bad ?dir= is a navigation miss, not an error.
     */
    public static function normalizeDir(?string $dir): string
    {
        // Strip only spaces and slashes at the edges — normalizePath
        // trims each segment, so control bytes are rejected here first.
        $dir = trim((string) $dir, ' /');

        if ($dir === '' || preg_match('/[\x00-\x1F\x7F]/', $dir)) {
            return '';
        }

        try {
            return VfsFile::normalizePath($dir).'/';
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    /**
     * One directory level: immediate subdirectory names plus the file
     * paths at exactly this level. Anything deeper surfaces only through
     * its subdirectory link.
     *
     * @param  iterable<int, string>  $paths  normalized paths, any order
     * @return array{dirs: list<string>, files: list<string>}
     */
    public static function level(iterable $paths, string $dir): array
    {
        $dirs = [];
        $files = [];

        foreach ($paths as $path) {
            if (! str_starts_with($path, $dir)) {
                continue;
            }

            $rest = substr($path, strlen($dir));
            $slash = strpos($rest, '/');

            if ($slash === false) {
                $files[] = $path;
            } else {
                $dirs[substr($rest, 0, $slash)] = true;
            }
        }

        ksort($dirs);
        sort($files);

        return ['dirs' => array_keys($dirs), 'files' => $files];
    }

    /**
     * Breadcrumb trail for a normalized dir: `[{name, dir}]` root-first,
     * each `dir` in normalized 'a/b/' form (root is '').
     *
     * @return list<array{name: string, dir: string}>
     */
    public static function breadcrumbs(string $dir): array
    {
        $crumbs = [['name' => '/', 'dir' => '']];
        $acc = '';

        foreach (explode('/', rtrim($dir, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            $acc .= $segment.'/';
            $crumbs[] = ['name' => $segment, 'dir' => $acc];
        }

        return $crumbs;
    }
}
