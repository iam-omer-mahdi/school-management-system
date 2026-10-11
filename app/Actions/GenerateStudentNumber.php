<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Student;

final class GenerateStudentNumber
{
    public function handle(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $max = Student::query()->max('id');
            $next = ((int) $max) + 1 + $attempt;
            $candidate = 'STU-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);

            if (! Student::query()->where('student_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        do {
            $candidate = 'STU-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Student::query()->where('student_number', $candidate)->exists());

        return $candidate;
    }
}
