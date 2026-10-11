<?php

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Computed]
    public function studentCount(): int
    {
        return Student::query()->count();
    }

    #[Computed]
    public function yearCount(): int
    {
        return AcademicYear::query()->count();
    }

    #[Computed]
    public function enrollmentCount(): int
    {
        return Enrollment::query()->count();
    }
};
?>

<div class="space-y-4">
    <h1 class="text-xl font-semibold">{{ __('dashboard.title') }}</h1>
    <p class="text-sm text-gray-600">{{ __('dashboard.welcome') }} — {{ __('dashboard.placeholder_note') }}</p>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded bg-white p-4 shadow">
            <div class="text-sm text-gray-500">{{ __('dashboard.students_card') }}</div>
            <div class="text-2xl font-semibold">{{ $this->studentCount }}</div>
            <a href="{{ route('students.index') }}" wire:navigate class="text-sm text-blue-700 hover:underline">{{ __('common.students') }}</a>
        </div>
        <div class="rounded bg-white p-4 shadow">
            <div class="text-sm text-gray-500">{{ __('dashboard.years_card') }}</div>
            <div class="text-2xl font-semibold">{{ $this->yearCount }}</div>
            <a href="{{ route('academic-years.index') }}" wire:navigate class="text-sm text-blue-700 hover:underline">{{ __('common.academic_years') }}</a>
        </div>
        <div class="rounded bg-white p-4 shadow">
            <div class="text-sm text-gray-500">{{ __('dashboard.enrollments_card') }}</div>
            <div class="text-2xl font-semibold">{{ $this->enrollmentCount }}</div>
        </div>
    </div>
</div>
