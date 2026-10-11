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

<div class="space-y-4">
    <h1 class="text-xl font-semibold">{{ __('schools.title') }}</h1>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        @foreach($this->schools as $school)
            <div wire:key="school-{{ $school->id }}" class="rounded bg-white p-4 shadow">
                <h2 class="font-medium">{{ __('schools.'.$school->code) }}</h2>
                <ul class="mt-2 space-y-1 text-sm text-gray-700">
                    @foreach($school->grades as $grade)
                        <li wire:key="grade-{{ $grade->id }}">{{ __('schools.grade_number', ['number' => $grade->number]) }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</div>
