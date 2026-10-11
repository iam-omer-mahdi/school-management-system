<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\EnrollmentDiscountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single tuition discount for an enrollment, stored in basis points.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $percent_bp
 * @property int $amount_minor
 * @property string|null $note
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class EnrollmentDiscount extends Model
{
    /** @use HasFactory<EnrollmentDiscountFactory> */
    use HasFactory;

    protected $fillable = ['enrollment_id', 'percent_bp', 'amount_minor', 'note'];

    protected function casts(): array
    {
        return [
            'percent_bp' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
