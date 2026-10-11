<?php

declare(strict_types=1);

namespace App\Enums;

enum DisplayLocale: string
{
    case English = 'en';
    case Arabic = 'ar';

    public function isRtl(): bool
    {
        return $this === self::Arabic;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
