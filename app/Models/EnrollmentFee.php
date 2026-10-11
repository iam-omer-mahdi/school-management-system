<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\EnrollmentFeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A frozen copy of the grade fee amount at assignment time.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $grade_fee_id
 * @property int $amount_minor
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class EnrollmentFee extends Model
{
    /** @use HasFactory<EnrollmentFeeFactory> */
    use HasFactory;

    protected $fillable = ['enrollment_id', 'grade_fee_id', 'amount_minor'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<GradeFee, $this> */
    public function gradeFee(): BelongsTo
    {
        return $this->belongsTo(GradeFee::class);
    }
}
