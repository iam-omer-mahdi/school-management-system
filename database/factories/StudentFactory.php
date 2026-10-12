<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_number' => fake()->unique()->numerify('STU-######'),
            'name' => fake()->name(),
            'phone' => fake()->numerify('09########'),
            'secondary_phone' => null,
            'home_address' => null,
            'mother_name' => null,
            'blood_type' => null,
            'date_of_birth' => null,
            'guardian_name' => null,
            'guardian_relationship' => null,
            'archived_at' => null,
            'archive_note' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived_at' => now(),
        ]);
    }
}
