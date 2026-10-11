<?php

declare(strict_types=1);

return [
    'tuition' => 'Tuition',
    'currency' => 'SDG',
    'name' => 'Fee type name',
    'amount' => 'Amount',
    'academic_year' => 'Academic year',
    'fee_type' => 'Fee type',
    'discount' => 'Discount',
    'note' => 'Note',
    'errors' => [
        'name_taken' => 'This fee type name is already used.',
        'reserved_name' => 'This name is reserved.',
        'tuition_protected' => 'Tuition can never be deleted.',
        'type_in_use' => 'This fee type is used in a fee schedule and cannot be deleted.',
        'fee_in_use' => 'This fee was already charged to enrollments and cannot be deleted.',
        'schedule_taken' => 'This fee is already set for the grade and year.',
        'not_optional' => 'Only optional fees can be added to a student.',
        'grade_mismatch' => 'This fee does not belong to the enrollment grade and year.',
        'already_charged' => 'This fee is already charged to the enrollment.',
        'charge_not_found' => 'This charge does not exist.',
        'mandatory_locked' => 'Mandatory charges cannot be removed.',
        'fees_not_set' => 'Tuition is not set for this grade and year yet.',
        'discount_missing' => 'No discount is set for this enrollment.',
        'grade_not_found' => 'Grade not found.',
    ],
];
