@props([
    'title' => '',
    'academicYear' => null,
    'school' => null,
    'grade' => null,
    'statusLabel' => null,
])

<div class="mb-6 border-b-2 border-gray-900 pb-4">
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="text-xs font-semibold tracking-wider text-gray-500 uppercase">{{ config('app.name') }}</div>
            <h1 class="mt-0.5 text-xl font-bold text-gray-900">{{ $title }}</h1>
            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-600">
                @if($academicYear)
                    <span><strong>{{ __('fees.academic_year') }}:</strong> {{ $academicYear }}</span>
                @endif
                <span><strong>{{ __('fees.school') }}:</strong> {{ $school ?? __('reports.all_schools') }}</span>
                @if($grade)
                    <span><strong>{{ __('fees.grade') }}:</strong> {{ $grade }}</span>
                @endif
                @if($statusLabel)
                    <span><strong>{{ __('reports.status_filter') }}:</strong> {{ $statusLabel }}</span>
                @endif
            </div>
        </div>
        <div class="shrink-0 text-end text-xs text-gray-500">
            <div>{{ __('reports.generated_at') }}:</div>
            <div dir="ltr" class="font-mono text-gray-700">{{ now()->format('Y-m-d H:i') }}</div>
        </div>
    </div>
</div>
