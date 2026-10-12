<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named discount in the catalog. Discounts are create-only; the name
 * and percentage are snapshotted into enrollment discounts at apply time.
 *
 * @property int $id
 * @property string $name
 * @property int $percent_bp
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory;

    protected $fillable = ['name', 'percent_bp'];

    protected function casts(): array
    {
        return [
            'percent_bp' => 'integer',
        ];
    }

    /** @return HasMany<EnrollmentDiscount, $this> */
    public function enrollmentDiscounts(): HasMany
    {
        return $this->hasMany(EnrollmentDiscount::class);
    }
}
