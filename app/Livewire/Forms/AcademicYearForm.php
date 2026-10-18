<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\AcademicYear;
use Closure;
use Illuminate\Support\Carbon;
use Livewire\Form;

class AcademicYearForm extends Form
{
    /** How many years back an academic year may still start. */
    private const YEARS_BACK_LIMIT = 3;

    public string $name = '';

    /** @var ?string month in "YYYY-MM" format */
    public ?string $starts_on = null;

    /** @var ?string two-digit month, "01" to "12" */
    public ?string $end_month = null;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date_format:Y-m', $this->uniqueStartYearRule(), $this->startYearFloorRule()],
            'end_month' => ['required', 'date_format:m'],
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
     * The display name derived from the start month, e.g. "2026/2027":
     * a year always ends in the following calendar year.
     */
    public function generatedName(): string
    {
        if ($this->starts_on === null || ! preg_match('/^(\d{4})-\d{2}$/', $this->starts_on, $start)) {
            return '';
        }

        return $start[1].'/'.((int) $start[1] + 1);
    }

    /** Refresh the readonly name preview after the start month changes. */
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
            'end_month' => __('academic_years.ends_on'),
        ];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $this->validate();

        $startYear = (int) substr((string) $this->starts_on, 0, 4);

        return [
            'name' => $this->generatedName(),
            'starts_on' => $this->starts_on.'-01',
            'ends_on' => ($startYear + 1).'-'.$this->end_month.'-01',
        ];
    }

    public function store(): AcademicYear
    {
        return AcademicYear::query()->create($this->data());
    }

    /**
     * Month choices for the end of the year, labelled in the current locale
     * by Carbon's bundled translations.
     *
     * @return array<string, string>
     */
    public static function monthOptions(): array
    {
        $locale = app()->getLocale();
        $options = [];

        foreach (range(1, 12) as $month) {
            $value = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
            $options[$value] = Carbon::createFromDate(1970, (int) $value, 1)->settings(['locale' => $locale])->translatedFormat('F');
        }

        return $options;
    }

    public function resetForm(): void
    {
        $this->reset(['name', 'starts_on', 'end_month']);
    }
}
