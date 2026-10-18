<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SchoolType;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => SchoolType::Elementary,
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
