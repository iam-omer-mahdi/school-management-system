<?php

declare(strict_types=1);

return [
    'payment' => 'دفعة',
    'paid' => 'مدفوع',
    'remaining' => 'المتبقي',
    'overpaid' => 'مدفوع بالزيادة',
    'refund' => 'استرداد',
    'refunds' => 'الاستردادات',
    'method' => 'طريقة الدفع',
    'cash' => 'نقدًا',
    'bank_transfer' => 'تحويل بنكي',
    'transaction_number' => 'رقم العملية',
    'paid_on' => 'تاريخ الدفع',
    'refunded_on' => 'تاريخ الاسترداد',
    'amount' => 'المبلغ',
    'add_payment' => 'إضافة دفعة',
    'record_overpayment' => 'تسجيل كدفعة زائدة',
    'unallocated' => 'غير مخصص',
    'void' => 'إلغاء',
    'void_reason' => 'سبب الإلغاء',
    'receipt' => 'إيصال',
    'print' => 'طباعة',
    'print_receipt' => 'طباعة الإيصال',
    'closed_without_payment' => 'أُغلق دون سداد',
    'will_no_longer_be_owed' => ':amount لن يعد مستحقًا',
    'errors' => [
        'already_voided' => 'هذا الصف ملغي بالفعل.',
        'amount_exceeds_remaining' => 'المبلغ أكبر من الرصيد المتبقي.',
        'refund_exceeds_outstanding' => 'المبلغ أكبر من المدفوع بالزيادة المستحق.',
        'void_blocked_by_refunds' => 'لا يمكن إلغاء هذه الدفعة الزائدة لوجود استردادات عليها.',
        'ended_no_payments' => 'لا يمكن تسجيل مدفوعات لتسجيل منتهٍ.',
    ],
];
