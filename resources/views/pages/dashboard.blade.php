<?php

use App\Models\AcademicYear;
use App\Support\Reports\FinancialSummary;
use App\Support\Reports\ReportQueries;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Url]
    public ?int $academic_year_id = null;

    public function mount(): void
    {
        $this->academic_year_id ??= AcademicYear::query()->orderByDesc('starts_on')->value('id');
    }

    #[Computed]
    public function years(): \Illuminate\Support\Collection
    {
        return AcademicYear::query()->select(['id', 'name'])->orderByDesc('starts_on')->get();
    }

    #[Computed]
    public function summary(): array
    {
        return FinancialSummary::forSelection(['academic_year_id' => $this->academic_year_id]);
    }

    #[Computed]
    public function gradeSummary(): array
    {
        return FinancialSummary::yearSummary(['academic_year_id' => $this->academic_year_id]);
    }

    #[Computed]
    public function feeTypeSummary(): array
    {
        return ReportQueries::byFeeType(['academic_year_id' => $this->academic_year_id]);
    }

    #[Computed]
    public function monthlyCollections(): array
    {
        if ($this->academic_year_id === null) {
            return [];
        }

        return ReportQueries::monthlyCollections($this->academic_year_id);
    }
};
?>

@php
    $sum = $this->summary;
    $gradeSum = $this->gradeSummary;
    $ftSum = $this->feeTypeSummary;
    $monthly = $this->monthlyCollections;

    $gradeLabels = [];
    $collected = [];
    $remaining = [];
    foreach ($gradeSum['grades'] as $g) {
        $gradeLabels[] = $g['school_name'].' '.__('schools.grade_number', ['number' => $g['grade_number']]);
        $collected[] = round($g['current'] / 100, 2);
        $remaining[] = round($g['remaining'] / 100, 2);
    }

    $statusLabels = [];
    $statusValues = [];
    foreach ($sum['by_status'] as $st => $cnt) {
        if ($cnt > 0) {
            $statusLabels[] = __('enrollments.status_'.$st);
            $statusValues[] = $cnt;
        }
    }

    $monthLabels = [];
    $monthValues = [];
    foreach ($monthly as $m) {
        $monthLabels[] = $m['month'];
        $monthValues[] = round($m['net_cash'] / 100, 2);
    }

    $feeLabels = [];
    $feeValues = [];
    foreach ($ftSum['fee_types'] as $ft) {
        $feeLabels[] = $ft['is_tuition'] ? __('fees.tuition') : $ft['name'];
        $feeValues[] = round($ft['grand_total'] / 100, 2);
    }
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <x-ui.page-header :title="__('dashboard.title')" :description="__('dashboard.welcome')" />
        <div class="w-full sm:w-60">
            <x-ui.select wire:model.live="academic_year_id" aria-label="{{ __('fees.academic_year') }}">
                @foreach($this->years as $year)
                    <option value="{{ $year->id }}">{{ $year->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.card>
            <div class="text-xs font-semibold text-gray-500 uppercase dark:text-gray-400">{{ __('reports.grand_total') }}</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><x-ui.money :minor="$sum['grand_total']" /></div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $sum['count'] }} {{ __('reports.enrollments_count') }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs font-semibold text-emerald-600 uppercase dark:text-emerald-400">{{ __('reports.current') }}</div>
            <div class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400"><x-ui.money :minor="$sum['current']" /></div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $sum['percent_paid'] !== null ? number_format($sum['percent_paid'], 1).'%' : '—' }} {{ __('reports.percent_paid') }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs font-semibold text-amber-600 uppercase dark:text-amber-400">{{ __('reports.still_owed') }}</div>
            <div class="mt-2 text-2xl font-bold text-amber-600 dark:text-amber-400"><x-ui.money :minor="$sum['still_owed']" /></div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('reports.preset_charged') }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs font-semibold text-rose-600 uppercase dark:text-rose-400">{{ __('reports.forgone') }}</div>
            <div class="mt-2 text-2xl font-bold text-rose-600 dark:text-rose-400"><x-ui.money :minor="$sum['forgone']" /></div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('reports.preset_ended') }}</div>
        </x-ui.card>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <x-ui.card>
            <h3 class="mb-4 text-sm font-bold text-gray-900 dark:text-white">{{ __('reports.year_summary') }}</h3>
            @if($gradeLabels === [])
                <x-ui.empty-state :title="__('reports.no_records')" />
            @else
                <div data-chart="grade-stacked" data-labels='@json($gradeLabels)' data-collected='@json($collected)' data-remaining='@json($remaining)' data-cash-label="{{ __('reports.current') }}" data-rest-label="{{ __('reports.remaining') }}" class="h-72" dir="ltr"></div>
            @endif
        </x-ui.card>
        <x-ui.card>
            <h3 class="mb-4 text-sm font-bold text-gray-900 dark:text-white">{{ __('reports.status_filter') }}</h3>
            @if($statusValues === [])
                <x-ui.empty-state :title="__('reports.no_records')" />
            @else
                <div data-chart="status-donut" data-labels='@json($statusLabels)' data-values='@json($statusValues)' class="h-72" dir="ltr"></div>
            @endif
        </x-ui.card>
        <x-ui.card>
            <h3 class="mb-4 text-sm font-bold text-gray-900 dark:text-white">{{ __('reports.collections') }}</h3>
            @if($monthValues === [])
                <x-ui.empty-state :title="__('reports.no_records')" />
            @else
                <div data-chart="monthly-line" data-labels='@json($monthLabels)' data-values='@json($monthValues)' data-net-label="{{ __('reports.cash_net') }}" class="h-72" dir="ltr"></div>
            @endif
        </x-ui.card>
        <x-ui.card>
            <h3 class="mb-4 text-sm font-bold text-gray-900 dark:text-white">{{ __('reports.tuition_vs_other') }}</h3>
            @if($feeValues === [])
                <x-ui.empty-state :title="__('reports.no_records')" />
            @else
                <div data-chart="fee-donut" data-labels='@json($feeLabels)' data-values='@json($feeValues)' class="h-72" dir="ltr"></div>
            @endif
        </x-ui.card>
    </div>
</div>
