<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\GradeFeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $grade_id
 * @property int $academic_year_id
 * @property int $fee_type_id
 * @property int $amount_minor
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class GradeFee extends Model
{
    /** @use HasFactory<GradeFeeFactory> */
    use HasFactory;

    protected $fillable = ['grade_id', 'academic_year_id', 'fee_type_id', 'amount_minor'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Grade, $this> */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<FeeType, $this> */
    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    /** @return HasMany<EnrollmentFee, $this> */
    public function enrollmentFees(): HasMany
    {
        return $this->hasMany(EnrollmentFee::class);
    }
}
