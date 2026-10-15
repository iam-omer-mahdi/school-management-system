{{-- Forgone by leavers: by-grade summary plus per-student details.
     $summary (forgoneByGrade), $details (forgoneListQuery rows).
     Screen passes $linkNames=true for profile links; print passes neither. --}}
<div class="overflow-x-auto rounded-lg border border-gray-700 dark:border-gray-600">
    <table class="report-table w-full text-sm">
        <thead>
            <tr>
                <th>{{ __('fees.school') }}</th>
                <th>{{ __('fees.grade') }}</th>
                <th class="text-center">{{ __('reports.students_count') }}</th>
                <th class="text-end">{{ __('reports.forgone') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($summary as $f)
                <tr>
                    <td>{{ __('schools.'.$f->school_code) }}</td>
                    <td>{{ __('schools.grade_number', ['number' => $f->grade_number]) }}</td>
                    <td class="text-center">{{ $f->students_count }}</td>
                    <td class="text-end font-bold"><x-ui.money :minor="(int) $f->forgone_minor" /></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">{{ __('payments.total') }}</td>
                <td class="text-center">{{ $summary->sum('students_count') }}</td>
                <td class="text-end"><x-ui.money :minor="(int) $summary->sum('forgone_minor')" /></td>
            </tr>
        </tfoot>
    </table>
</div>

<h3 class="mt-6 mb-2 text-sm font-bold text-gray-900 dark:text-white">{{ __('reports.forgone_details') }}</h3>
<div class="overflow-x-auto rounded-lg border border-gray-700 dark:border-gray-600">
    <table class="report-table w-full text-sm">
        <thead>
            <tr>
                <th>{{ __('students.student_number') }}</th>
                <th>{{ __('students.name') }}</th>
                <th>{{ __('fees.grade') }}</th>
                <th>{{ __('enrollments.status') }}</th>
                <th class="text-end">{{ __('reports.forgone') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($details as $item)
                @php($st = $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status)
                <tr>
                    <td dir="ltr" class="font-mono text-xs">{{ $item->student_number }}</td>
                    <td class="font-medium">
                        @if(! empty($linkNames))
                            <a href="{{ route('students.show', $item->student_id) }}" wire:navigate class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $item->student_name }}</a>
                        @else
                            {{ $item->student_name }}
                        @endif
                    </td>
                    <td>{{ __('schools.'.$item->school_code) }} {{ __('schools.grade_number', ['number' => $item->grade_number]) }}</td>
                    <td><x-ui.badge :status="$st" /></td>
                    <td class="text-end font-bold"><x-ui.money :minor="(int) $item->forgone_minor" /></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">{{ __('payments.total') }} ({{ $details->count() }})</td>
                <td class="text-end"><x-ui.money :minor="(int) $details->sum('forgone_minor')" /></td>
            </tr>
        </tfoot>
    </table>
</div>
