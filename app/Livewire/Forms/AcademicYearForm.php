<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\AcademicYear;
use Livewire\Form;

class AcademicYearForm extends Form
{
    public string $name = '';

    public ?string $starts_on = null;

    public ?string $ends_on = null;

    public ?int $editingId = null;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:20', 'unique:academic_years,name'.($this->editingId ? ','.$this->editingId : '')],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $this->validate();

        return [
            'name' => trim($this->name),
            'starts_on' => $this->starts_on ?: null,
            'ends_on' => $this->ends_on ?: null,
        ];
    }

    public function store(): AcademicYear
    {
        return AcademicYear::query()->create($this->data());
    }

    public function update(AcademicYear $year): AcademicYear
    {
        $year->update($this->data());

        return $year->refresh();
    }

    public function fillFrom(AcademicYear $year): void
    {
        $this->editingId = $year->id;
        $this->name = $year->name;
        $this->starts_on = $year->starts_on?->format('Y-m-d');
        $this->ends_on = $year->ends_on?->format('Y-m-d');
    }

    public function resetForm(): void
    {
        $this->reset(['name', 'starts_on', 'ends_on', 'editingId']);
    }
}
