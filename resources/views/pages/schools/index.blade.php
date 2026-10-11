<?php

use App\Models\School;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    /** @return \Illuminate\Database\Eloquent\Collection<int, School> */
    #[Computed]
    public function schools(): \Illuminate\Database\Eloquent\Collection
    {
        return School::query()
            ->select(['id', 'code', 'name'])
            ->with(['grades:id,school_id,number'])
            ->orderBy('id')
            ->get();
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="__('schools.title')" />
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        @foreach($this->schools as $school)
            <x-ui.card wire:key="school-{{ $school->id }}">
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-900 dark:text-indigo-300">
                            <x-ui.icon name="cap" class="h-5 w-5" />
                        </span>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('schools.'.$school->code) }}</h2>
                    </div>
                </x-slot:header>
                <ul class="flex flex-wrap gap-1.5">
                    @foreach($school->grades as $grade)
                        <li wire:key="grade-{{ $grade->id }}">
                            <x-ui.badge color="gray">{{ __('schools.grade_number', ['number' => $grade->number]) }}</x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endforeach
    </div>
</div>
