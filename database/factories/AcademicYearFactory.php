<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    protected $model = AcademicYear::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = fake()->numberBetween(24, 30) + 2000;
        $startMonth = fake()->numberBetween(1, 9);
        $endMonth = fake()->numberBetween($startMonth, 12);

        return [
            'name' => sprintf('%d/%d', $startYear, $startYear + 1),
            'starts_on' => sprintf('%d-%02d-01', $startYear, $startMonth),
            'ends_on' => sprintf('%d-%02d-01', $startYear + 1, $endMonth),
        ];
    }
}
