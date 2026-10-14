<?php

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Reports\FinancialSummary;
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
        if ($this->academic_year_id === null) {
            $latest = AcademicYear::query()->orderByDesc('starts_on')->first();
            $this->academic_year_id = $latest?->id;
        }
    }

    #[Computed]
    public function years(): \Illuminate\Database\Eloquent\Collection
    {
        return AcademicYear::query()->select(['id', 'name'])->orderByDesc('starts_on')->get();
    }

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
        $q = Enrollment::query();
        if ($this->academic_year_id) {
            $q->where('academic_year_id', $this->academic_year_id);
        }

        return $q->count();
    }

    #[Computed]
    public function summary(): array
    {
        return FinancialSummary::forSelection([
            'academic_year_id' => $this->academic_year_id,
        ]);
    }

    #[Computed]
    public function gradeSummary(): array
    {
        return FinancialSummary::yearSummary([
            'academic_year_id' => $this->academic_year_id,
        ]);
    }

    #[Computed]
    public function feeTypeSummary(): array
    {
        return FinancialSummary::byFeeType([
            'academic_year_id' => $this->academic_year_id,
        ]);
    }

    #[Computed]
    public function monthlyCollections(): array
    {
        if (! $this->academic_year_id) {
            return [];
        }

        return FinancialSummary::monthlyCollections($this->academic_year_id);
    }
};
?>

@php
    $sum = $this->summary;
    $gradeSum = $this->gradeSummary;
    $ftSum = $this->feeTypeSummary;
    $monthly = $this->monthlyCollections;

    // 1. Grade stacked bar: collected vs remaining
    $gradeLabels = [];
    $collectedSeries = [];
    $remainingSeries = [];
    foreach ($gradeSum['grades'] as $g) {
        $gradeLabels[] = __('schools.'.$g['school_code']).' '.__('schools.grade_number', ['number' => $g['grade_number']]);
        $collectedSeries[] = round($g['current'] / 100, 2);
        $remainingSeries[] = round($g['remaining'] / 100, 2);
    }

    // 2. Enrollment status donut
    $statusLabels = [];
    $statusSeries = [];
    foreach ($sum['by_status'] as $st => $cnt) {
        if ($cnt > 0) {
            $statusLabels[] = __('enrollments.status_'.$st);
            $statusSeries[] = $cnt;
        }
    }

    // 3. Monthly collections line
    $monthLabels = [];
    $monthSeries = [];
    foreach ($monthly as $m) {
        $monthLabels[] = $m['month'];
        $monthSeries[] = round($m['net_cash'] / 100, 2);
    }

    // 4. Tuition vs Other fees bar/donut
    $feeLabels = [];
    $feeSeries = [];
    foreach ($ftSum['fee_types'] as $ft) {
        $feeLabels[] = $ft['is_tuition'] ? __('fees.tuition') : $ft['name'];
        $feeSeries[] = round($ft['grand_total'] / 100, 2);
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

    {{-- Key metric cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.card>
            <div class="text-xs font-semibold text-gray-500 uppercase">{{ __('reports.grand_total') }}</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                <x-ui.money :minor="$sum['grand_total']" />
            </div>
            <div class="mt-1 text-xs text-gray-500">{{ $this->enrollmentCount }} {{ __('reports.enrollments_count') }}</div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-xs font-semibold text-emerald-600 uppercase dark:text-emerald-400">{{ __('reports.current') }}</div>
            <div class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                <x-ui.money :minor="$sum['current']" />
            </div>
            <div class="mt-1 text-xs text-gray-500">
                {{ $sum['percent_paid'] !== null ? number_format($sum['percent_paid'], 1).'%' : '—' }} {{ __('reports.percent_paid') }}
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-xs font-semibold text-amber-600 uppercase dark:text-amber-400">{{ __('reports.still_owed') }}</div>
            <div class="mt-2 text-2xl font-bold text-amber-600 dark:text-amber-400">
                <x-ui.money :minor="$sum['still_owed']" />
            </div>
            <div class="mt-1 text-xs text-gray-500">{{ __('reports.preset_charged') }}</div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-xs font-semibold text-rose-600 uppercase dark:text-rose-400">{{ __('reports.forgone') }}</div>
            <div class="mt-2 text-2xl font-bold text-rose-600 dark:text-rose-400">
                <x-ui.money :minor="$sum['forgone']" />
            </div>
            <div class="mt-1 text-xs text-gray-500">{{ __('reports.preset_ended') }}</div>
        </x-ui.card>
    </div>

    {{-- 4 Charts Section --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2" wire:ignore>
        {{-- Chart 1: Stacked Bar (Collected vs Remaining per grade) --}}
        <x-ui.card>
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4">{{ __('reports.year_summary') }}</h3>
            <div id="chart-grade-stacked" class="h-72"></div>
        </x-ui.card>

        {{-- Chart 2: Enrollment status split (Donut) --}}
        <x-ui.card>
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4">{{ __('reports.status_filter') }}</h3>
            <div id="chart-status-donut" class="h-72"></div>
        </x-ui.card>

        {{-- Chart 3: Monthly collections (Line) --}}
        <x-ui.card>
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4">{{ __('reports.collections') }}</h3>
            <div id="chart-monthly-line" class="h-72"></div>
        </x-ui.card>

        {{-- Chart 4: Tuition vs Other Fees --}}
        <x-ui.card>
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4">{{ __('reports.tuition_vs_other') }}</h3>
            <div id="chart-fee-donut" class="h-72"></div>
        </x-ui.card>
    </div>
</div>

<script>
    document.addEventListener('livewire:navigated', () => {
        renderDashboardCharts();
    });

    document.addEventListener('DOMContentLoaded', () => {
        renderDashboardCharts();
    });

    function renderDashboardCharts() {
        if (!window.ApexCharts) return;

        const isDark = document.documentElement.classList.contains('dark');
        const themeMode = isDark ? 'dark' : 'light';

        // 1. Grade stacked bar
        const el1 = document.querySelector('#chart-grade-stacked');
        if (el1) {
            el1.innerHTML = '';
            new ApexCharts(el1, {
                series: [
                    { name: @js(__('reports.current')), data: @js($collectedSeries) },
                    { name: @js(__('reports.remaining')), data: @js($remainingSeries) }
                ],
                chart: { type: 'bar', height: 280, stacked: true, background: 'transparent', toolbar: { show: false } },
                colors: ['#10b981', '#f59e0b'],
                xaxis: { categories: @js($gradeLabels) },
                theme: { mode: themeMode }
            }).render();
        }

        // 2. Status Donut
        const el2 = document.querySelector('#chart-status-donut');
        if (el2) {
            el2.innerHTML = '';
            new ApexCharts(el2, {
                series: @js($statusSeries),
                labels: @js($statusLabels),
                chart: { type: 'donut', height: 280, background: 'transparent' },
                theme: { mode: themeMode },
                legend: { position: 'bottom' }
            }).render();
        }

        // 3. Monthly Line
        const el3 = document.querySelector('#chart-monthly-line');
        if (el3) {
            el3.innerHTML = '';
            new ApexCharts(el3, {
                series: [{ name: @js(__('reports.cash_net')), data: @js($monthSeries) }],
                chart: { type: 'line', height: 280, background: 'transparent', toolbar: { show: false } },
                xaxis: { categories: @js($monthLabels) },
                stroke: { curve: 'smooth', width: 3 },
                colors: ['#6366f1'],
                theme: { mode: themeMode }
            }).render();
        }

        // 4. Fee types
        const el4 = document.querySelector('#chart-fee-donut');
        if (el4) {
            el4.innerHTML = '';
            new ApexCharts(el4, {
                series: @js($feeSeries),
                labels: @js($feeLabels),
                chart: { type: 'donut', height: 280, background: 'transparent' },
                theme: { mode: themeMode },
                legend: { position: 'bottom' }
            }).render();
        }
    }
</script>
