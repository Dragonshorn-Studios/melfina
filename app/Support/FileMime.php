<?php

namespace App\Support;

/**
 * MIME helpers shared by the file surfaces: what the uploader declared
 * (header / tool arg), what the bytes sniff as, and which types may render
 * inline in the owner console. Agent-authored HTML/SVG must never render
 * inline — that would be XSS inside the owner's session.
 */
final class FileMime
{
    /**
     * Mimes that count as text for storage decisions — UTF-8 bodies with
     * one of these stay in `content` so the console keeps them editable.
     * Everything else, and anything that fails UTF-8 validation, is a blob.
     */
    private const TEXTUAL_MIMES = [
        'application/json',
        'application/javascript',
        'application/xml',
        'application/x-yaml',
        'application/yaml',
        'application/toml',
        'application/x-sh',
        'image/svg+xml',
    ];

    /**
     * Mimes that are technically image/text but execute script (or carry
     * active content) when served inline — they download instead.
     */
    private const NOT_INLINE_SAFE = [
        'text/html',
        'text/javascript',
        'application/javascript',
        'application/xhtml+xml',
        'application/xml',
        'text/xml',
        'image/svg+xml',
        'image/svg',
    ];

    /**
     * Mimes safe to render inline in the owner console: images (minus SVG),
     * inert text, PDF. Anything else — including every scriptable type —
     * downloads as application/octet-stream.
     */
    public static function isInlineSafe(string $mime): bool
    {
        if (in_array($mime, self::NOT_INLINE_SAFE, true)) {
            return false;
        }

        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'text/')
            || $mime === 'application/pdf';
    }

    /**
     * Clean a caller-supplied mime down to a bare `type/subtype` token, or
     * null when the value isn't shaped like one.
     */
    public static function normalize(?string $mime): ?string
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        return preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~', $mime) === 1
            ? $mime
            : null;
    }

    /**
     * Sniff the actual bytes — always beats a caller declaration.
     */
    public static function sniff(string $bytes): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($mime) ? self::normalize($mime) : null;
    }

    /**
     * Store-as-text check: UTF-8 bytes with a text-ish declared (or absent)
     * mime. Anything else lands in the blob column.
     */
    public static function isTextual(string $bytes, ?string $mime): bool
    {
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return false;
        }

        if ($mime === null) {
            return true;
        }

        return str_starts_with($mime, 'text/') || in_array($mime, self::TEXTUAL_MIMES, true);
    }
}
