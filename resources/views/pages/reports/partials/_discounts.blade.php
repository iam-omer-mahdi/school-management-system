{{-- Discounts by snapshot name. Expects $discounts (collection). --}}
<div class="overflow-x-auto rounded-lg border border-gray-700 dark:border-gray-600">
    <table class="report-table w-full text-sm">
        <thead>
            <tr>
                <th>{{ __('reports.discount_name') }}</th>
                <th class="text-center">{{ __('reports.students_count') }}</th>
                <th class="text-end">{{ __('reports.total_amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($discounts as $d)
                <tr>
                    <td class="font-medium">{{ $d->name }}</td>
                    <td class="text-center">{{ $d->students_count }}</td>
                    <td class="text-end"><x-ui.money :minor="(int) $d->total_amount_minor" /></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('payments.total') }}</td>
                <td class="text-center">{{ $discounts->sum('students_count') }}</td>
                <td class="text-end"><x-ui.money :minor="(int) $discounts->sum('total_amount_minor')" /></td>
            </tr>
        </tfoot>
    </table>
</div>
