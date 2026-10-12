<?php

declare(strict_types=1);

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Transferred = 'transferred';
    case Expelled = 'expelled';
    case Left = 'left';

    /**
     * Enrollments with these statuses receive new mandatory fees;
     * transferred, expelled and left enrollments are "ended".
     */
    public function isCharged(): bool
    {
        return $this === self::Active || $this === self::Suspended;
    }
}
