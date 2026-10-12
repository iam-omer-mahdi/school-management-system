<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared name normalization for catalog names (fee types, discounts).
 * normalize() cleans invisible characters and whitespace for storage;
 * comparisonKey() folds Arabic variants and case for duplicate checks.
 */
final class TextNormalizer
{
    /**
     * Trim, drop zero-width and bidi control characters, turn NBSP into a
     * regular space, then collapse all whitespace runs to a single space.
     */
    public static function normalize(string $value): string
    {
        $stripped = str_replace(
            ["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}",
                "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}",
                "\u{2060}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}",
                "\u{FEFF}"],
            '',
            trim($value)
        );

        $spaced = str_replace("\u{00A0}", ' ', $stripped);

        /** @var string $collapsed */
        $collapsed = preg_replace('/\s+/u', ' ', $spaced);

        return $collapsed;
    }

    /**
     * Fold Arabic variants for duplicate detection: strip tashkeel and
     * tatweel, unify alef forms to ا, ى to ي, ة to ه, then lowercase.
     */
    public static function comparisonKey(string $value): string
    {
        $normalized = self::normalize($value);

        // Remove tatweel (U+0640), tashkeel (U+064B-U+065F) and superscript alef (U+0670).
        /** @var string $bare */
        $bare = preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}]/u', '', $normalized);

        $folded = strtr($bare, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',
        ]);

        return mb_strtolower($folded, 'UTF-8');
    }
}
