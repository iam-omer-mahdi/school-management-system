<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Discount;
use App\Models\Enrollment;
use App\Models\EnrollmentDiscount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentDiscount>
 */
class EnrollmentDiscountFactory extends Factory
{
    protected $model = EnrollmentDiscount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'discount_id' => Discount::factory(),
            'name' => fake()->words(2, true),
            'percent_bp' => 1000,
            'amount_minor' => fake()->numberBetween(1000, 50000),
        ];
    }
}
