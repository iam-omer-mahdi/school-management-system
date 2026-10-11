<?php

declare(strict_types=1);

namespace App\Enums;

enum StudentStatus: string
{
    case Active = 'active';
    case Transferred = 'transferred';
    case Expelled = 'expelled';
    case Left = 'left';
    case Suspended = 'suspended';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
