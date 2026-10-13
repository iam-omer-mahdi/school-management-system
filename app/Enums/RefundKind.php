<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Refund kinds. Only overpayment refunds exist for now; Step 4 may
 * add fee-money refunds, which is why the kind column exists.
 */
enum RefundKind: string
{
    case Overpayment = 'overpayment';
}
