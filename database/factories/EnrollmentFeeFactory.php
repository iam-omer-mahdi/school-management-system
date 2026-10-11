<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\GradeFee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentFee>
 */
class EnrollmentFeeFactory extends Factory
{
    protected $model = EnrollmentFee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'grade_fee_id' => GradeFee::factory(),
            'amount_minor' => fake()->numberBetween(10000, 500000),
        ];
    }
}
