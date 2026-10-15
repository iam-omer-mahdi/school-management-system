{{-- Tuition vs other fees. Expects $feeTypes (ReportQueries::byFeeType result). --}}
<div class="overflow-x-auto rounded-lg border border-gray-700 dark:border-gray-600">
    <table class="report-table w-full text-sm">
        <thead>
            <tr>
                <th>{{ __('reports.fee_type') }}</th>
                <th class="text-end">{{ __('reports.grand_total') }}</th>
                <th class="text-end">{{ __('reports.current') }}</th>
                <th class="text-end">{{ __('reports.remaining') }}</th>
                <th class="text-center">{{ __('reports.percent_paid') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($feeTypes['fee_types'] as $ft)
                <tr>
                    <td class="font-medium">{{ $ft['is_tuition'] ? __('fees.tuition') : $ft['name'] }}</td>
                    <td class="text-end"><x-ui.money :minor="$ft['grand_total']" /></td>
                    <td class="text-end"><x-ui.money :minor="$ft['current']" /></td>
                    <td class="text-end"><x-ui.money :minor="$ft['remaining']" /></td>
                    <td class="text-center">{{ $ft['percent_paid'] !== null ? number_format($ft['percent_paid'], 1).'%' : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('payments.total') }}</td>
                <td class="text-end"><x-ui.money :minor="$feeTypes['totals']['grand_total']" /></td>
                <td class="text-end"><x-ui.money :minor="$feeTypes['totals']['current']" /></td>
                <td class="text-end"><x-ui.money :minor="$feeTypes['totals']['remaining']" /></td>
                <td class="text-center">{{ $feeTypes['totals']['percent_paid'] !== null ? number_format($feeTypes['totals']['percent_paid'], 1).'%' : '—' }}</td>
            </tr>
        </tfoot>
    </table>
</div>
