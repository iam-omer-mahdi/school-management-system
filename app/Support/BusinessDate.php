<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The school's calendar day, which is not always the server's.
 *
 * Timestamps are stored in UTC, but a payment or refund made just after
 * midnight at the school belongs to the school's date, not UTC's. Every
 * "is this date in the future?" check and every date form default must ask
 * here instead of calling date('Y-m-d'), which reads the server clock.
 */
final class BusinessDate
{
    public static function today(): string
    {
        return now((string) config('app.school_timezone'))->toDateString();
    }
}
