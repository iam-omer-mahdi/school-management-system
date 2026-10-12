<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Discount;
use App\Support\Percent;
use App\Support\TextNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateDiscount
{
    /**
     * Add a named discount to the catalog. Names are normalized with
     * TextNormalizer and duplicate-checked by comparisonKey like fee
     * types; the percentage is parsed with the Percent helper.
     *
     * @param  array{name?: string, percent?: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Discount
    {
        $name = TextNormalizer::normalize((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => __('fees.discount_name')]),
            ]);
        }

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => __('validation.max.string', ['attribute' => __('fees.discount_name'), 'max' => 255]),
            ]);
        }

        try {
            $basisPoints = Percent::parse((string) ($data['percent'] ?? ''));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'percent' => __('validation.numeric', ['attribute' => __('fees.percentage')]),
            ]);
        }

        if ($this->isDuplicate($name)) {
            throw ValidationException::withMessages([
                'name' => __('fees.errors.discount_name_taken'),
            ]);
        }

        try {
            return DB::transaction(fn (): Discount => Discount::query()->create([
                'name' => $name,
                'percent_bp' => $basisPoints,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => __('fees.errors.discount_name_taken'),
            ]);
        }
    }

    private function isDuplicate(string $name): bool
    {
        $key = TextNormalizer::comparisonKey($name);

        foreach (Discount::query()->pluck('name') as $existing) {
            if (TextNormalizer::comparisonKey((string) $existing) === $key) {
                return true;
            }
        }

        return false;
    }
}
