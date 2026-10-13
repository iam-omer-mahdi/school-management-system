<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\RefundKind;
use App\Models\Enrollment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'kind' => RefundKind::Overpayment,
            'amount_minor' => fake()->numberBetween(100, 100000),
            'method' => PaymentMethod::Cash,
            'transaction_number' => null,
            'refunded_on' => fake()->date(),
            'note' => 'Overpayment refund.',
            'voided_at' => null,
            'void_reason' => null,
        ];
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes): array => [
            'voided_at' => now(),
            'void_reason' => 'Entered by mistake.',
        ]);
    }
}
