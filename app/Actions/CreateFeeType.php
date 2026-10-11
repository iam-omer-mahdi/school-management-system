<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FeeType;
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
        $name = $this->normalize((string) ($data['name'] ?? ''));

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

        // The utf8mb4_unicode_ci collation makes this comparison
        // case-insensitive, so "Bus" and "bus" collide here.
        if (FeeType::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages([
                'name' => __('fees.errors.name_taken'),
            ]);
        }

        try {
            return DB::transaction(fn (): FeeType => FeeType::query()->create([
                'name' => $name,
                'is_mandatory' => (bool) ($data['is_mandatory'] ?? false),
            ]));
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'name' => __('fees.errors.name_taken'),
            ]);
        }
    }

    public function normalize(string $value): string
    {
        /** @var string $collapsed */
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return $collapsed;
    }

    private function isReserved(string $name): bool
    {
        $candidates = [
            'Tuition',
            __('fees.tuition', locale: 'en'),
            __('fees.tuition', locale: 'ar'),
            'رسوم دراسية',
        ];

        foreach ($candidates as $reserved) {
            if (mb_strtolower($name, 'UTF-8') === mb_strtolower($reserved, 'UTF-8')) {
                return true;
            }
        }

        return false;
    }
}
