<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FeeType;
use App\Support\TextNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateFeeType
{
    /**
     * @param  array{name?: string, is_mandatory?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): FeeType
    {
        $name = TextNormalizer::normalize((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => __('fees.name')]),
            ]);
        }

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => __('validation.max.string', ['attribute' => __('fees.name'), 'max' => 255]),
            ]);
        }

        if ($this->isReserved($name)) {
            throw ValidationException::withMessages([
                'name' => __('fees.errors.reserved_name'),
            ]);
        }

        // Duplicate detection folds Arabic variants and case, so "Bus"
        // and "bus" collide here, as do alef/hamza and ة/ه spellings.
        if ($this->isDuplicate($name)) {
            throw ValidationException::withMessages([
                'name' => __('fees.errors.name_taken'),
            ]);
        }

        try {
            return DB::transaction(fn (): FeeType => FeeType::query()->create([
                'name' => $name,
                'is_mandatory' => (bool) ($data['is_mandatory'] ?? false),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => __('fees.errors.name_taken'),
            ]);
        }
    }

    private function isDuplicate(string $name): bool
    {
        $key = TextNormalizer::comparisonKey($name);

        foreach (FeeType::query()->pluck('name') as $existing) {
            if (TextNormalizer::comparisonKey((string) $existing) === $key) {
                return true;
            }
        }

        return false;
    }

    private function isReserved(string $name): bool
    {
        $key = TextNormalizer::comparisonKey($name);

        $candidates = [
            'Tuition',
            __('fees.tuition', locale: 'en'),
            __('fees.tuition', locale: 'ar'),
            'رسوم دراسية',
        ];

        foreach ($candidates as $reserved) {
            if (TextNormalizer::comparisonKey($reserved) === $key) {
                return true;
            }
        }

        return false;
    }
}
