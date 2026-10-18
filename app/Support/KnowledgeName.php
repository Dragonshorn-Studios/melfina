<?php

namespace App\Support;

use Normalizer;

/**
 * Single normalizer for identity indexes. Trim, Unicode NFC when the
 * intl Normalizer is present, then UTF-8 case fold. Polish letters stay.
 * No ASCII folding, no fuzzy matching, no stripping diacritics.
 */
final class KnowledgeName
{
    public const OPERATOR_TOKEN = 'operator';

    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        return mb_strtolower($value, 'UTF-8');
    }

    public static function isOperatorToken(string $normalized): bool
    {
        return $normalized === self::OPERATOR_TOKEN;
    }
}
