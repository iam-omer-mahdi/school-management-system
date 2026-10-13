<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'enrollment_fee_id' => EnrollmentFee::factory(),
            'amount_minor' => fake()->numberBetween(100, 100000),
            'kind' => PaymentKind::Fee,
            'method' => PaymentMethod::Cash,
            'transaction_number' => null,
            'paid_on' => fake()->date(),
            'note' => null,
            'voided_at' => null,
            'void_reason' => null,
        ];
    }

    public function overpayment(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enrollment_fee_id' => null,
            'kind' => PaymentKind::Overpayment,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes): array => [
            'voided_at' => now(),
            'void_reason' => 'Entered by mistake.',
        ]);
    }
}
