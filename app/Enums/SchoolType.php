<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A school's type decides its grade list and is fixed once the school exists
 * (plan D6): changing it would rewrite the grades under live enrollments.
 */
enum SchoolType: string
{
    case Elementary = 'elementary';
    case Middle = 'middle';
    case High = 'high';

    /**
     * The grades this type opens with (plan D9).
     *
     * @return array<int, int>
     */
    public function gradeNumbers(): array
    {
        return match ($this) {
            self::Elementary => [1, 2, 3, 4, 5, 6],
            self::Middle, self::High => [1, 2, 3],
        };
    }

    /** The type's own name, for the read-only display once a school exists. */
    public function label(): string
    {
        return __('schools.'.$this->value);
    }
}
