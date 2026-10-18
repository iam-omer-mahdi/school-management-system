<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\AcademicYear;
use Closure;
use Livewire\Form;

class AcademicYearForm extends Form
{
    /** How many years back an academic year may still start. */
    private const YEARS_BACK_LIMIT = 3;

    public string $name = '';

    /** @var ?string month in "YYYY-MM" format */
    public ?string $starts_on = null;

    /** @var ?string month in "YYYY-MM" format */
    public ?string $ends_on = null;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date_format:Y-m', $this->uniqueStartYearRule(), $this->startYearFloorRule()],
            'ends_on' => ['required', 'date_format:Y-m', $this->endYearAfterStartRule()],
            'name' => ['nullable', 'string'],
        ];
    }

    /**
     * Reject a start month whose calendar year is already taken by another
     * academic year (e.g. a second year starting in 2026).
     */
    private function uniqueStartYearRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/^(\d{4})-\d{2}$/', $value, $matches)) {
                return;
            }

            if (AcademicYear::query()->whereYear('starts_on', $matches[1])->exists()) {
                $fail(__('academic_years.already_exists_for_year', ['year' => $matches[1]]));
            }
        };
    }

    /**
     * A year may no longer be started once it is older than
     * YEARS_BACK_LIMIT years ("YYYY-MM" strings compare lexicographically).
     */
    private function startYearFloorRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if ($value < now()->subYears(self::YEARS_BACK_LIMIT)->format('Y-m')) {
                $fail(__('academic_years.start_month_too_old', ['years' => self::YEARS_BACK_LIMIT]));
            }
        };
    }

    /**
     * The year spans exactly one school year, so the end year is the start
     * year plus one. That also rules out a reversed or same-year pair.
     */
    private function endYearAfterStartRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! is_string($this->starts_on)) {
                return;
            }

            if (! preg_match('/^(\d{4})-\d{2}$/', $value, $end)) {
                return;
            }

            if (! preg_match('/^(\d{4})-\d{2}$/', $this->starts_on, $start)) {
                return;
            }

            if ((int) $end[1] !== (int) $start[1] + 1) {
                $fail(__('academic_years.end_year_after_start'));
            }
        };
    }

    /**
     * The display name derived from both months, e.g. "2026/2027".
     * Empty until both months are present.
     */
    public function generatedName(): string
    {
        if ($this->starts_on === null || $this->ends_on === null) {
            return '';
        }

        if (! preg_match('/^(\d{4})-\d{2}$/', $this->starts_on, $start)) {
            return '';
        }

        if (! preg_match('/^(\d{4})-\d{2}$/', $this->ends_on, $end)) {
            return '';
        }

        return $start[1].'/'.$end[1];
    }

    /** Refresh the readonly name preview after either month changes. */
    public function syncName(): void
    {
        $this->name = $this->generatedName();
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'name' => __('academic_years.name'),
            'starts_on' => __('academic_years.starts_on'),
            'ends_on' => __('academic_years.ends_on'),
        ];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $this->validate();

        return [
            'name' => $this->generatedName(),
            'starts_on' => $this->starts_on.'-01',
            'ends_on' => $this->ends_on.'-01',
        ];
    }

    public function store(): AcademicYear
    {
        return AcademicYear::query()->create($this->data());
    }

    public function resetForm(): void
    {
        $this->reset(['name', 'starts_on', 'ends_on']);
    }
}
