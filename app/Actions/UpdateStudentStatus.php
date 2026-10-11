<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\StudentStatus;
use App\Models\Student;

final class UpdateStudentStatus
{
    public function handle(Student $student, StudentStatus $status, ?string $note = null): Student
    {
        $student->status = $status;
        $student->status_note = $note;
        $student->status_changed_at = now();
        $student->save();

        return $student->refresh();
    }
}
