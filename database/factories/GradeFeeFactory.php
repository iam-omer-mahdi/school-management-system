<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeFee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeFee>
 */
class GradeFeeFactory extends Factory
{
    protected $model = GradeFee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'grade_id' => Grade::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'fee_type_id' => FeeType::factory(),
            'amount_minor' => fake()->numberBetween(10000, 500000),
        ];
    }
}
