<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Integer minor-unit money handling for the single-currency (SDG) system.
 * Amounts are never handled as float: parsing, math and formatting all
 * operate on integer minor units encoded as int or numeric strings.
 */
final class Money
{
    /**
     * Largest storable amount in minor units. Keeps minor * basis-points
     * products (≈1e17) far below the 64-bit signed limit (≈9.2e18).
     */
    public const MAX_MINOR = 9_999_999_999_999;

    /**
     * Normalize user input: Arabic-Indic digits to ASCII, the Arabic
     * decimal separator to a dot, then strip spaces and thousands
     * separators (comma and Arabic thousands separator).
     */
    public static function normalizeInput(string $value): string
    {
        $normalized = strtr(trim($value), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.',
        ]);

        $stripped = str_replace([',', '٬'], '', $normalized);

        // The /u flag makes \s match Unicode spaces too (including NBSP).
        return (string) preg_replace('/\s+/u', '', $stripped);
    }

    /**
     * Parse a decimal amount string ("1,250.50", "١٢٫٥") to minor units.
     *
     * @throws InvalidArgumentException
     */
    public static function fromDecimal(string $value): int
    {
        $normalized = self::normalizeInput($value);

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException('The amount is invalid.');
        }

        $minor = ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '', 2, '0');

        if ($minor > self::MAX_MINOR) {
            throw new InvalidArgumentException('The amount is too large.');
        }

        return $minor;
    }

    /**
     * Resolve a basis-points percentage of a minor-unit amount, rounding
     * half up to the nearest minor unit (nearest piastre).
     */
    public static function percentOf(int $amountMinor, int $basisPoints): int
    {
        return intdiv($amountMinor * $basisPoints + 5000, 10000);
    }

    /**
     * Format minor units for display: comma thousands separators, Western
     * digits, decimals only when non-zero, plus the currency label.
     */
    public static function format(int $amountMinor): string
    {
        // Abs() first: PHP keeps the sign on the remainder, so a negative
        // amount would otherwise lose its cents ("-1250" instead of "-12.50").
        $sign = $amountMinor < 0 ? '-' : '';
        $absolute = abs($amountMinor);

        $grouped = number_format(intdiv($absolute, 100));
        $cents = $absolute % 100;

        $text = $cents > 0
            ? $grouped.'.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT)
            : $grouped;

        return $sign.$text.' '.__('fees.currency');
    }
}
