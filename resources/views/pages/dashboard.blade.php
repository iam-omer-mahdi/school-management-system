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

<div class="space-y-5">
    <x-ui.page-header :title="__('dashboard.title')" :description="__('dashboard.welcome')" />

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.card>
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-900 dark:text-indigo-300">
                    <x-ui.icon name="users" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.students_card') }}</div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $this->studentCount }}</div>
                </div>
            </div>
            <div class="mt-3">
                <a href="{{ route('students.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">{{ __('common.students') }}</a>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-900 dark:text-indigo-300">
                    <x-ui.icon name="calendar" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.years_card') }}</div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $this->yearCount }}</div>
                </div>
            </div>
            <div class="mt-3">
                <a href="{{ route('academic-years.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">{{ __('common.academic_years') }}</a>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-900 dark:text-indigo-300">
                    <x-ui.icon name="cap" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="truncate text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.enrollments_card') }}</div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $this->enrollmentCount }}</div>
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
