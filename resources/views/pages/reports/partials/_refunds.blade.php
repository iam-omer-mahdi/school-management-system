{{-- Refund lines. Expects $refunds (iterable of Refund with relations).
     Screen passes $linkNames=true for profile links; print passes neither. --}}
<div class="overflow-x-auto rounded-lg border border-gray-700 dark:border-gray-600">
    <table class="report-table w-full text-sm">
        <thead>
            <tr>
                <th>{{ __('reports.refunded_on') }}</th>
                <th>{{ __('students.student_number') }}</th>
                <th>{{ __('students.name') }}</th>
                <th>{{ __('fees.grade') }}</th>
                <th>{{ __('reports.fee_type') }}</th>
                <th>{{ __('enrollments.status') }}</th>
                <th>{{ __('payments.method') }}</th>
                <th class="text-end">{{ __('payments.amount') }}</th>
                <th>{{ __('reports.note') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($refunds as $r)
                @php($st = $r->enrollment->status instanceof \BackedEnum ? $r->enrollment->status->value : (string) $r->enrollment->status)
                <tr>
                    <td dir="ltr" class="font-mono text-xs">{{ $r->refunded_on?->format('Y-m-d') }}</td>
                    <td dir="ltr" class="font-mono text-xs">{{ $r->enrollment->student->student_number }}</td>
                    <td class="font-medium">
                        @if(! empty($linkNames))
                            <a href="{{ route('students.show', $r->enrollment->student->id) }}" wire:navigate class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $r->enrollment->student->name }}</a>
                        @else
                            {{ $r->enrollment->student->name }}
                        @endif
                    </td>
                    <td>{{ __('schools.'.$r->enrollment->grade->school->code) }} {{ __('schools.grade_number', ['number' => $r->enrollment->grade->number]) }}</td>
                    <td>{{ $r->enrollmentFee->gradeFee->feeType->isTuition() ? __('fees.tuition') : $r->enrollmentFee->gradeFee->feeType->name }}</td>
                    <td><x-ui.badge :status="$st" /></td>
                    <td>{{ $r->method === \App\Enums\PaymentMethod::Cash ? __('payments.cash') : __('payments.bank_transfer') }}</td>
                    <td class="text-end font-bold text-red-600 dark:text-red-400"><x-ui.money :minor="$r->amount_minor" /></td>
                    <td class="max-w-48 truncate text-gray-500" title="{{ $r->note }}">{{ $r->note }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="7">{{ __('payments.total') }}</td>
                <td class="text-end text-red-600 dark:text-red-400"><x-ui.money :minor="(int) collect($refunds)->sum('amount_minor')" /></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
