<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrollmentStatus;
use Carbon\CarbonInterface;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $student_id
 * @property int $academic_year_id
 * @property int $grade_id
 * @property EnrollmentStatus $status
 * @property string|null $status_note
 * @property CarbonInterface|null $status_changed_at
 */
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    protected $fillable = ['student_id', 'academic_year_id', 'grade_id', 'status', 'status_note', 'status_changed_at'];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    public function isCharged(): bool
    {
        return $this->status->isCharged();
    }

    public function isEnded(): bool
    {
        return ! $this->isCharged();
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Grade, $this> */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /** @return HasMany<EnrollmentFee, $this> */
    public function fees(): HasMany
    {
        return $this->hasMany(EnrollmentFee::class);
    }

    /** @return HasOne<EnrollmentDiscount, $this> */
    public function discount(): HasOne
    {
        return $this->hasOne(EnrollmentDiscount::class);
    }
}
