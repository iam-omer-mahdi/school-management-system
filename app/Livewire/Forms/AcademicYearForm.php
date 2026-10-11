<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\AcademicYear;
use Closure;
use Livewire\Form;

class AcademicYearForm extends Form
{
    public string $name = '';

    /** @var ?string month in "YYYY-MM" format */
    public ?string $starts_on = null;

    /** @var ?string month in "YYYY-MM" format */
    public ?string $ends_on = null;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date_format:Y-m', $this->uniqueStartYearRule()],
            'ends_on' => ['required', 'date_format:Y-m', $this->endAfterStartRule()],
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

    /** The end month must not be before the start month ("YYYY-MM" compares lexicographically). */
    private function endAfterStartRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! is_string($this->starts_on)) {
                return;
            }

            if ($value < $this->starts_on) {
                $fail(__('validation.after_or_equal', ['attribute' => __('academic_years.ends_on'), 'date' => __('academic_years.starts_on')]));
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
