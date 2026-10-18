<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Actions\CreateStudent;
use App\Models\Student;
use Closure;
use Livewire\Form;

class StudentForm extends Form
{
    /** @var list<string> */
    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public const MIN_AGE_YEARS = 5;

    public const MAX_AGE_YEARS = 40;

    public string $name = '';

    public string $phone = '';

    public ?string $secondary_phone = null;

    public ?string $home_address = null;

    public ?string $mother_name = null;

    public ?string $blood_type = null;

    public ?string $date_of_birth = null;

    public ?string $guardian_name = null;

    public ?string $guardian_relationship = null;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'secondary_phone' => ['nullable', 'string', 'max:30'],
            'home_address' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'blood_type' => ['nullable', 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'date_of_birth' => ['nullable', 'date', $this->dateOfBirthAgeRule()],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_relationship' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'name' => __('students.name'),
            'phone' => __('students.phone'),
            'secondary_phone' => __('students.secondary_phone'),
            'home_address' => __('students.home_address'),
            'mother_name' => __('students.mother_name'),
            'blood_type' => __('students.blood_type'),
            'date_of_birth' => __('students.date_of_birth'),
            'guardian_name' => __('students.guardian_name'),
            'guardian_relationship' => __('students.guardian_relationship'),
        ];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $this->validate();

        return [
            'name' => trim($this->name),
            'phone' => trim($this->phone),
            'secondary_phone' => $this->emptyToNull($this->secondary_phone),
            'home_address' => $this->emptyToNull($this->home_address),
            'mother_name' => $this->emptyToNull($this->mother_name),
            'blood_type' => $this->emptyToNull($this->blood_type),
            'date_of_birth' => $this->emptyToNull($this->date_of_birth),
            'guardian_name' => $this->emptyToNull($this->guardian_name),
            'guardian_relationship' => $this->emptyToNull($this->guardian_relationship),
        ];
    }

    public function store(CreateStudent $creator, ?int $academicYearId = null, ?int $gradeId = null): Student
    {
        return $creator->handle($this->data(), $academicYearId, $gradeId);
    }

    public function fillFrom(Student $student): void
    {
        $this->name = $student->name;
        $this->phone = $student->phone;
        $this->secondary_phone = $student->secondary_phone;
        $this->home_address = $student->home_address;
        $this->mother_name = $student->mother_name;
        $this->blood_type = $student->blood_type;
        $this->date_of_birth = $student->date_of_birth?->format('Y-m-d');
        $this->guardian_name = $student->guardian_name;
        $this->guardian_relationship = $student->guardian_relationship;
    }

    /**
     * A date of birth has to place the student inside the school-age
     * window: at least MIN_AGE_YEARS and at most MAX_AGE_YEARS old.
     * Both ends count as inside, and "YYYY-MM-DD" strings compare as text.
     */
    private function dateOfBirthAgeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return;
            }

            if ($value > now()->subYears(self::MIN_AGE_YEARS)->toDateString()) {
                $fail(__('students.errors.date_of_birth_too_young', ['min' => self::MIN_AGE_YEARS]));
            } elseif ($value < now()->subYears(self::MAX_AGE_YEARS)->toDateString()) {
                $fail(__('students.errors.date_of_birth_too_old', ['max' => self::MAX_AGE_YEARS]));
            }
        };
    }

    private function emptyToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
