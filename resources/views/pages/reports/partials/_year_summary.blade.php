{{-- Year summary: per grade + school totals. Expects $summary, $showStatus. --}}
<div class="overflow-x-auto rounded-lg border border-gray-700 dark:border-gray-600">
    <table class="report-table w-full text-sm">
        <thead>
            <tr>
                <th>{{ __('fees.school') }}</th>
                <th>{{ __('fees.grade') }}</th>
                <th class="text-center">{{ __('reports.enrollments_count') }}</th>
                <th class="text-end">{{ __('reports.grand_total') }}</th>
                <th class="text-end">{{ __('reports.current') }}</th>
                <th class="text-end">{{ __('reports.remaining') }}</th>
                <th class="text-end">{{ __('reports.still_owed') }}</th>
                <th class="text-end">{{ __('reports.forgone') }}</th>
                <th class="text-center">{{ __('reports.percent_paid') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($summary['grades'] as $g)
                <tr>
                    <td>{{ __('schools.'.$g['school_code']) }}</td>
                    <td>{{ __('schools.grade_number', ['number' => $g['grade_number']]) }}</td>
                    <td class="text-center">
                        <span class="font-medium">{{ $g['count'] }}</span>
                        @if($showStatus)
                            <div class="mt-0.5 flex flex-wrap justify-center gap-1 text-[11px] font-normal text-gray-500 dark:text-gray-400">
                                @foreach($g['by_status'] as $st => $cnt)
                                    @if($cnt > 0)
                                        <span>{{ __('enrollments.status_'.$st) }}: {{ $cnt }}</span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="text-end"><x-ui.money :minor="$g['grand_total']" /></td>
                    <td class="text-end"><x-ui.money :minor="$g['current']" /></td>
                    <td class="text-end"><x-ui.money :minor="$g['remaining']" /></td>
                    <td class="text-end"><x-ui.money :minor="$g['still_owed']" /></td>
                    <td class="text-end"><x-ui.money :minor="$g['forgone']" /></td>
                    <td class="text-center">{{ $g['percent_paid'] !== null ? number_format($g['percent_paid'], 1).'%' : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">{{ __('payments.total') }}</td>
                <td class="text-center">
                    {{ $summary['totals']['count'] }}
                    @if($showStatus)
                        <div class="mt-0.5 flex flex-wrap justify-center gap-1 text-[11px] font-normal">
                            @foreach($summary['totals']['by_status'] as $st => $cnt)
                                @if($cnt > 0)
                                    <span>{{ __('enrollments.status_'.$st) }}: {{ $cnt }}</span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </td>
                <td class="text-end"><x-ui.money :minor="$summary['totals']['grand_total']" /></td>
                <td class="text-end"><x-ui.money :minor="$summary['totals']['current']" /></td>
                <td class="text-end"><x-ui.money :minor="$summary['totals']['remaining']" /></td>
                <td class="text-end"><x-ui.money :minor="$summary['totals']['still_owed']" /></td>
                <td class="text-end"><x-ui.money :minor="$summary['totals']['forgone']" /></td>
                <td class="text-center">{{ $summary['totals']['percent_paid'] !== null ? number_format($summary['totals']['percent_paid'], 1).'%' : '—' }}</td>
            </tr>
        </tfoot>
    </table>
</div>
<p class="mt-3 text-xs text-gray-500 italic dark:text-gray-400">{{ __('reports.footnote_year_summary') }}</p>
