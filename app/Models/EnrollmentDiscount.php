<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\EnrollmentDiscountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single tuition discount for an enrollment, resolved from a catalog
 * discount. The name and percentage are snapshots; later catalog changes
 * never rewrite applied discounts.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $discount_id
 * @property string $name
 * @property int $percent_bp
 * @property int $amount_minor
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class EnrollmentDiscount extends Model
{
    /** @use HasFactory<EnrollmentDiscountFactory> */
    use HasFactory;

    protected $fillable = ['enrollment_id', 'discount_id', 'name', 'percent_bp', 'amount_minor'];

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

    /** @return BelongsTo<Discount, $this> */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
