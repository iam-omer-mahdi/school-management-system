{{-- Collections per day + voided audit. Expects $collections, $showAudit (bool). --}}
<div class="overflow-x-auto rounded-sm border border-line ">
    <table class="report-table w-full text-sm">
        <thead>
            <tr>
                <th>{{ __('payments.paid_on') }}</th>
                <th class="text-end">{{ __('payments.cash') }}</th>
                <th class="text-end">{{ __('payments.bank_transfer') }}</th>
                <th class="text-end">{{ __('reports.refunds') }}</th>
                <th>{{ __('reports.transaction_numbers') }}</th>
                <th class="text-center">{{ __('reports.events_count') }}</th>
                <th class="text-end">{{ __('reports.cash_net') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($collections['days'] as $day)
                <tr>
                    <td dir="ltr" class="font-mono text-xs">{{ $day['date'] }}</td>
                    <td class="text-end"><x-ui.money :minor="$day['cash']" /></td>
                    <td class="text-end"><x-ui.money :minor="$day['bank']" /></td>
                    <td class="text-end text-negative "><x-ui.money :minor="$day['refunds']" /></td>
                    <td dir="ltr" class="font-mono text-xs">{{ implode(', ', $day['txns']) ?: '—' }}</td>
                    <td class="text-center">{{ $day['events'] }}</td>
                    <td class="text-end font-bold"><x-ui.money :minor="$day['net']" /></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('payments.total') }}</td>
                <td class="text-end"><x-ui.money :minor="$collections['total_cash']" /></td>
                <td class="text-end"><x-ui.money :minor="$collections['total_bank']" /></td>
                <td class="text-end text-negative "><x-ui.money :minor="-$collections['total_refunds']" /></td>
                <td></td>
                <td class="text-center">{{ $collections['events'] }}</td>
                <td class="text-end"><x-ui.money :minor="$collections['net_total']" /></td>
            </tr>
        </tfoot>
    </table>
</div>

@if($showAudit && ($collections['voided_payments']->isNotEmpty() || $collections['voided_refunds']->isNotEmpty()))
    <h3 class="mt-8 text-sm font-bold tracking-wider text-negative uppercase ">{{ __('reports.audit_voided') }}</h3>
    <div class="mt-3 space-y-2 text-xs">
        @foreach($collections['voided_payments'] as $vp)
            <div class="rounded border border-negative bg-negative-surface p-2 ">
                <span class="font-mono font-bold" dir="ltr">PAY-{{ str_pad((string) $vp->id, 6, '0', STR_PAD_LEFT) }}</span>
                <span dir="ltr">{{ $vp->paid_on }}</span>
                <span><x-ui.money :minor="(int) $vp->amount_minor" /></span>
                <div class="text-muted">{{ __('reports.void_reason') }}: {{ $vp->void_reason }}</div>
            </div>
        @endforeach
        @foreach($collections['voided_refunds'] as $vr)
            <div class="rounded border border-negative bg-negative-surface p-2 ">
                <span class="font-mono font-bold" dir="ltr">REF-{{ str_pad((string) $vr->id, 6, '0', STR_PAD_LEFT) }}</span>
                <span dir="ltr">{{ $vr->refunded_on }}</span>
                <span><x-ui.money :minor="(int) $vr->amount_minor" /></span>
                <div class="text-muted">{{ __('reports.void_reason') }}: {{ $vr->void_reason }}</div>
            </div>
        @endforeach
    </div>
@endif
