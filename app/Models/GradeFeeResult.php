<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Outcome of scheduling a grade fee: the frozen schedule row plus how
 * many enrollments were charged or skipped (ended statuses).
 */
final readonly class GradeFeeResult
{
    public function __construct(
        public GradeFee $gradeFee,
        public int $applied,
        public int $skipped,
    ) {}
}
