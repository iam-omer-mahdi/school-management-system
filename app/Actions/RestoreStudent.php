<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class RestoreStudent
{
    /**
     * Restore an archived student. Touches no enrollments and no fees.
     */
    public function handle(Student|int $student): Student
    {
        return DB::transaction(function () use ($student): Student {
            $row = $student instanceof Student ? $student : Student::query()->findOrFail($student);

            $row->archived_at = null;
            $row->archive_note = null;
            $row->save();

            return $row->refresh();
        });
    }
}
