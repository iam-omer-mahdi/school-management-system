<?php

declare(strict_types=1);

return [
    'payment' => 'Payment',
    'paid' => 'Paid',
    'remaining' => 'Remaining',
    'overpaid' => 'Overpaid',
    'refund' => 'Refund',
    'refunds' => 'Refunds',
    'method' => 'Method',
    'cash' => 'Cash',
    'bank_transfer' => 'Bank transfer',
    'transaction_number' => 'Transaction number',
    'paid_on' => 'Payment date',
    'refunded_on' => 'Refund date',
    'amount' => 'Amount',
    'add_payment' => 'Add payment',
    'record_overpayment' => 'Record as overpayment',
    'unallocated' => 'Unallocated',
    'void' => 'Void',
    'void_reason' => 'Void reason',
    'receipt' => 'Receipt',
    'print' => 'Print',
    'print_receipt' => 'Print receipt',
    'closed_without_payment' => 'Closed without payment',
    'will_no_longer_be_owed' => ':amount will no longer be owed',
    'errors' => [
        'amount_exceeds_remaining' => 'The amount is more than the remaining balance.',
        'refund_exceeds_outstanding' => 'The amount is more than the outstanding overpayment.',
        'void_blocked_by_refunds' => 'This overpayment cannot be voided while refunds against it exist.',
        'ended_no_payments' => 'No payments can be recorded for an ended enrollment.',
    ],
];
