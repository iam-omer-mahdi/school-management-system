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
    public string $archive = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedArchive(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function students(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Student::query()
            ->select(['id', 'student_number', 'name', 'phone', 'archived_at'])
            ->search($this->search)
            ->withArchiveFilter($this->archive ?: null)
            ->with(['enrollments.academicYear:id,name,starts_on', 'enrollments.grade:id,school_id,number'])
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

<div class="space-y-5">
    <x-ui.page-header :title="__('students.title')">
        <x-slot:actions>
            <x-ui.button href="{{ route('students.create') }}" wire:navigate variant="primary" size="sm" :spinner="false" class="w-full sm:w-auto">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('students.create') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        <div class="flex flex-col gap-3 sm:items-end sm:flex-row">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-gray-400">
                    <x-ui.icon name="search" class="h-4 w-4" />
                </span>
                <x-ui.input type="search" wire:model.live.debounce.300ms="search" :placeholder="__('students.search_placeholder')" aria-label="{{ __('students.search_placeholder') }}" class="ps-9" />
            </div>
            <x-ui.field :label="__('students.filter_archive')" for="archive-filter" class="sm:w-52">
                <x-ui.select id="archive-filter" wire:model.live="archive">
                    <option value="">{{ __('students.filter_active') }}</option>
                    <option value="archived">{{ __('students.filter_archived') }}</option>
                    <option value="all">{{ __('students.filter_all') }}</option>
                </x-ui.select>
            </x-ui.field>
        </div>
    </x-ui.card>

    @if($this->students->isEmpty())
        <x-ui.empty-state :title="__('common.no_results')" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>{{ __('students.student_number') }}</th>
                <th>{{ __('students.name') }}</th>
                <th>{{ __('students.phone') }}</th>
                <th>{{ __('students.latest_enrollment') }}</th>
            </x-slot:head>
            @foreach($this->students as $student)
                @php
                    $latest = $student->enrollments
                        ->sortByDesc(fn ($e) => [$e->academicYear?->starts_on?->format('Y-m-d') ?? '', $e->id])
                        ->first();
                @endphp
                <tr wire:key="student-{{ $student->id }}">
                    <td><a href="{{ route('students.show', $student) }}" wire:navigate class="font-medium text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">{{ $student->student_number }}</a></td>
                    <td><a href="{{ route('students.show', $student) }}" wire:navigate class="text-indigo-700 hover:underline focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:text-indigo-400 dark:focus:ring-indigo-800">{{ $student->name }}</a>
                        @if($student->isArchived())
                            <x-ui.badge color="gray">{{ __('students.archived') }}</x-ui.badge>
                        @endif
                    </td>
                    <td>{{ $student->phone }}</td>
                    <td>
                        @if($latest)
                            <span>{{ $latest->academicYear->name }} — {{ __('schools.grade_number', ['number' => $latest->grade->number]) }}</span>
                            <x-ui.badge :status="$latest->status->value" />
                        @else
                            {{ __('enrollments.not_enrolled') }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="mt-4">{{ $this->students->links() }}</div>
    @endif
</div>
