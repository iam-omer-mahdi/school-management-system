<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Percentage handling in integer basis points (100% = 10000 bp).
 * Parsing shares Money's input normalization; display uses Western digits
 * with decimals only when non-zero ("10%", "12.5%", "0.01%").
 */
final class Percent
{
    /**
     * Parse user input ("12.5", "١٢٫٥") to basis points. Accepts only
     * 0.01..100 (1..10000 bp).
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $value): int
    {
        $normalized = Money::normalizeInput($value);

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException('The percentage is invalid.');
        }

        $basisPoints = ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '', 2, '0');

        if ($basisPoints < 1 || $basisPoints > 10000) {
            throw new InvalidArgumentException('The percentage is invalid.');
        }

        return $basisPoints;
    }

    /**
     * Format basis points for display (1000 -> "10%", 1250 -> "12.5%").
     */
    public static function format(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $remainder = $basisPoints % 100;

        if ($remainder === 0) {
            return $whole.'%';
        }

        if ($remainder % 10 === 0) {
            return $whole.'.'.intdiv($remainder, 10).'%';
        }

        return $whole.'.'.str_pad((string) $remainder, 2, '0', STR_PAD_LEFT).'%';
    }
}
