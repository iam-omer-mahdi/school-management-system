{{-- Outstanding call list. $items rows, $totalOwed, $totalCount. Screen passes
     $linkNames=true and $sort for clickable sort headers; print passes neither. --}}
@php($sortable = ($sort ?? null) !== null)
<div class="overflow-x-auto rounded-lg border border-gray-700 dark:border-gray-600">
    <table class="report-table w-full text-sm">
        <thead>
            <tr>
                <th>{{ __('students.student_number') }}</th>
                <th>
                    @if($sortable)
                        <button type="button" wire:click="setCallSort('name')" class="inline-flex cursor-pointer items-center gap-1 font-bold hover:underline">
                            {{ __('students.name') }}
                            @if(str_starts_with($sort, 'name_'))
                                <span aria-hidden="true">{{ str_ends_with($sort, '_asc') ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    @else
                        {{ __('students.name') }}
                    @endif
                </th>
                <th>{{ __('students.phone') }}</th>
                <th>{{ __('students.secondary_phone') }}</th>
                <th>{{ __('students.guardian_name') }}</th>
                <th>{{ __('fees.grade') }}</th>
                <th>{{ __('enrollments.status') }}</th>
                <th class="text-end">
                    @if($sortable)
                        <button type="button" wire:click="setCallSort('owed')" class="inline-flex cursor-pointer items-center gap-1 font-bold hover:underline">
                            {{ __('reports.still_owed') }}
                            @if(str_starts_with($sort, 'owed_'))
                                <span aria-hidden="true">{{ str_ends_with($sort, '_asc') ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    @else
                        {{ __('reports.still_owed') }}
                    @endif
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
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
                    <td dir="ltr">{{ $item->phone }}</td>
                    <td dir="ltr">{{ $item->secondary_phone ?? '—' }}</td>
                    <td>{{ $item->guardian_name ?? '—' }}</td>
                    <td>{{ $item->school_name }} {{ __('schools.grade_number', ['number' => $item->grade_number]) }}</td>
                    <td><x-ui.badge :status="$st" /></td>
                    <td class="text-end font-bold text-red-600 dark:text-red-400"><x-ui.money :minor="(int) $item->owed_minor" /></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="7">{{ __('payments.total') }} ({{ $totalCount }} {{ __('students.enrollments') }})</td>
                <td class="text-end text-red-600 dark:text-red-400"><x-ui.money :minor="$totalOwed" /></td>
            </tr>
        </tfoot>
    </table>
</div>
