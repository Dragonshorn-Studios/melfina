<?php

namespace App\Mcp;

/**
 * The two generated plugin-package layouts. `multi` ships one
 * skills/melfina-<tool>/SKILL.md per catalogued tool — skill-only
 * runtimes read the file for the tool they need. `single` ships just the
 * umbrella skills/melfina/SKILL.md plus scripts/melfina-call.mjs, a
 * zero-dependency Node shim that validates arguments against
 * melfina-api.json, retries throttled calls, and prints actionable
 * errors — the tool surface is discovered at run time (`… list`)
 * instead of one file per tool.
 */
enum PackageVariant: string
{
    case Multi = 'multi';
    case Single = 'single';

    /**
     * Marker distinguishing the non-default layout in a clone URL or a
     * build dir: `{slug}.single.git` serves the single-skill package,
     * `{slug}.git` the multi-skill one. A `.` can never appear in an
     * agent slug (`Str::slug` strips it), so the marker can't collide
     * with a real name.
     */
    public function repoMarker(): string
    {
        return $this === self::Single ? '.single' : '';
    }

    /**
     * The variant a plugin repo name selects — `anything.single.git` is
     * the single-skill layout, every other `*.git` is multi.
     */
    public static function fromRepoName(string $repo): self
    {
        return str_ends_with($repo, '.single.git') ? self::Single : self::Multi;
    }

    /**
     * Lenient form parsing for console inputs (`?variant=`, publish
     * form): anything unrecognized is the default multi layout.
     */
    public static function fromRequest(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::Multi;
    }
}
