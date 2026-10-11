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
        $start = fake()->numberBetween(24, 30);

        return [
            'name' => sprintf('%d/%d', $start, $start + 1),
            'starts_on' => null,
            'ends_on' => null,
        ];
    }
}
