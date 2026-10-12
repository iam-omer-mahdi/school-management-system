<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ArchiveStudent
{
    /**
     * Archive a student. Touches no enrollments and no fees; existing
     * enrollments keep accepting discounts, status changes and payments.
     * Only new enrollments are blocked while archived.
     *
     * @throws ValidationException
     */
    public function handle(Student|int $student, ?string $note = null): Student
    {
        if ($note !== null && mb_strlen($note) > 255) {
            throw ValidationException::withMessages([
                'archive_note' => __('validation.max.string', ['attribute' => __('students.archive_note'), 'max' => 255]),
            ]);
        }

        return DB::transaction(function () use ($student, $note): Student {
            $row = $student instanceof Student ? $student : Student::query()->findOrFail($student);

            $row->archived_at = now();
            $row->archive_note = $note;
            $row->save();

            return $row->refresh();
        });
    }
}
