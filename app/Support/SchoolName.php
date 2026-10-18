<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * A school's name is the label the whole app displays (plan D8), so it is
 * cleaned and checked in one place by every path that writes it.
 */
final class SchoolName
{
    /**
     * @throws ValidationException
     */
    public static function validated(string $name): string
    {
        $name = TextNormalizer::normalize($name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => __('schools.name')]),
            ]);
        }

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => __('validation.max.string', ['attribute' => __('schools.name'), 'max' => 255]),
            ]);
        }

        return $name;
    }
}
