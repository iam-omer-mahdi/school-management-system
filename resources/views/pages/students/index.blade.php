<?php

use App\Models\Student;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function students(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Student::query()
            ->select(['id', 'student_number', 'name', 'phone', 'status'])
            ->search($this->search)
            ->withStatusFilter($this->status ?: null)
            ->orderBy('name')
            ->paginate(15);
    }

    #[Computed]
    public function queryCount(): int
    {
        return 1;
    }
};
?>

<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">{{ __('students.title') }}</h1>
        <a href="{{ route('students.create') }}" wire:navigate class="rounded bg-gray-900 px-4 py-2 text-white hover:bg-gray-700">{{ __('students.create') }}</a>
    </div>

    <div class="flex flex-col gap-2 sm:flex-row">
        <div class="flex-1">
            <x-search-input wire:model.live.debounce.300ms="search" :placeholder="__('students.search_placeholder')" />
        </div>
        <select wire:model.live="status" class="rounded border-gray-300 px-3 py-2">
            <option value="">{{ __('students.all_statuses') }}</option>
            <option value="active">{{ __('students.status_active') }}</option>
            <option value="transferred">{{ __('students.status_transferred') }}</option>
            <option value="expelled">{{ __('students.status_expelled') }}</option>
            <option value="left">{{ __('students.status_left') }}</option>
            <option value="suspended">{{ __('students.status_suspended') }}</option>
        </select>
    </div>

    <div class="overflow-x-auto rounded bg-white shadow">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b">
                    <th class="p-2 text-start">{{ __('students.student_number') }}</th>
                    <th class="p-2 text-start">{{ __('students.name') }}</th>
                    <th class="p-2 text-start">{{ __('students.phone') }}</th>
                    <th class="p-2 text-start">{{ __('students.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($this->students as $student)
                    <tr wire:key="student-{{ $student->id }}" class="border-b hover:bg-gray-50">
                        <td class="p-2"><a href="{{ route('students.show', $student) }}" wire:navigate class="text-blue-700 hover:underline">{{ $student->student_number }}</a></td>
                        <td class="p-2"><a href="{{ route('students.show', $student) }}" wire:navigate class="text-blue-700 hover:underline">{{ $student->name }}</a></td>
                        <td class="p-2">{{ $student->phone }}</td>
                        <td class="p-2"><x-status-badge :status="$student->status->value" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($this->students->isEmpty())
        <p class="text-sm text-gray-600">{{ __('common.no_results') }}</p>
    @endif
    {{ $this->students->links() }}
</div>
