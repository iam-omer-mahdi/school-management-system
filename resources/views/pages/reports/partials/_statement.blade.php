{{-- Student account statement, all years. Expects $student (with enrollments + relations). --}}
<div class="mb-4">
    <h2 class="text-base font-bold text-gray-900 dark:text-white">{{ $student->name }}</h2>
    <div class="text-xs text-gray-600 dark:text-gray-400">
        {{ __('students.student_number') }}: <span dir="ltr">{{ $student->student_number }}</span>
        · {{ __('students.phone') }}: <span dir="ltr">{{ $student->phone }}</span>
    </div>
</div>

@foreach($student->enrollments as $enr)
    @php($bal = \App\Support\EnrollmentBalance::forEnrollment($enr))
    <div class="mb-6 rounded border border-gray-300 p-3 dark:border-gray-600">
        <div class="mb-2 flex flex-wrap justify-between gap-2 border-b border-gray-200 pb-2 text-xs font-bold dark:border-gray-700">
            <span>{{ $enr->academicYear->name }} · {{ $enr->grade->school->name }} {{ __('schools.grade_number', ['number' => $enr->grade->number]) }} ({{ __('enrollments.status_'.$enr->status->value) }})</span>
            <span>{{ __('reports.still_owed') }}: <x-ui.money :minor="$bal['owed']" /></span>
        </div>
        <table class="report-table w-full text-xs">
            <thead>
                <tr>
                    <th>{{ __('reports.fee_type') }}</th>
                    <th class="text-end">{{ __('reports.amount') }}</th>
                    <th class="text-end">{{ __('fees.discount') }}</th>
                    <th class="text-end">{{ __('payments.paid') }}</th>
                    <th class="text-end">{{ __('reports.refunds') }}</th>
                    <th class="text-end">{{ __('reports.remaining') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($bal['fees'] as $feeId => $fee)
                    @php($model = $enr->fees->firstWhere('id', $feeId))
                    @php($type = $model?->gradeFee?->feeType)
                    <tr>
                        <td>{{ $type?->isTuition() ? __('fees.tuition') : $type?->name }}</td>
                        <td class="text-end"><x-ui.money :minor="$fee['charged']" /></td>
                        <td class="text-end"><x-ui.money :minor="$fee['discount']" /></td>
                        <td class="text-end"><x-ui.money :minor="$fee['paid']" /></td>
                        <td class="text-end"><x-ui.money :minor="$fee['refunded']" /></td>
                        <td class="text-end"><x-ui.money :minor="$fee['remaining']" /></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>{{ __('payments.total') }}</td>
                    <td class="text-end"><x-ui.money :minor="$bal['charged']" /></td>
                    <td class="text-end"><x-ui.money :minor="$bal['discount']" /></td>
                    <td class="text-end"><x-ui.money :minor="$bal['paid']" /></td>
                    <td class="text-end"><x-ui.money :minor="$bal['refunded']" /></td>
                    <td class="text-end"><x-ui.money :minor="$bal['charged'] - $bal['discount'] - $bal['net_paid']" /></td>
                </tr>
            </tfoot>
        </table>
    </div>
@endforeach
