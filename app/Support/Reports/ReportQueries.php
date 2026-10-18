<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\PaymentMethod;
use App\Models\Enrollment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Detail queries behind each report tab. Every method scopes through
 * FinancialSummary::baseQuery so the status filter (current enrollment
 * status) applies to every number on the page. Voided rows are excluded
 * from totals everywhere and surfaced only in the audit section.
 */
final class ReportQueries
{
    /**
     * Tuition vs other fees by fee type. The discount snapshot total is
     * subtracted from tuition only; other fee types keep full charges.
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed}  $filters
     * @return array{fee_types: Collection<int, array{fee_type_id: int, name: string, is_tuition: bool, grand_total: int, current: int, remaining: int, percent_paid: float|null}>, totals: array{grand_total: int, current: int, remaining: int, percent_paid: float|null}}
     */
    public static function byFeeType(array $filters = []): array
    {
        $ids = (clone FinancialSummary::baseQuery($filters))->select('enrollments.id');

        $discountTotal = (int) DB::table('enrollment_discounts')
            ->whereIn('enrollment_id', $ids)
            ->sum('amount_minor');

        $paidPerFee = DB::table('payments')
            ->whereNull('voided_at')
            ->select('enrollment_fee_id', DB::raw('SUM(amount_minor) AS paid_minor'))
            ->groupBy('enrollment_fee_id');

        $refundedPerFee = DB::table('refunds')
            ->whereNull('voided_at')
            ->select('enrollment_fee_id', DB::raw('SUM(amount_minor) AS refunded_minor'))
            ->groupBy('enrollment_fee_id');

        $rows = DB::table('enrollment_fees')
            ->whereIn('enrollment_fees.enrollment_id', $ids)
            ->join('grade_fees', 'grade_fees.id', '=', 'enrollment_fees.grade_fee_id')
            ->join('fee_types', 'fee_types.id', '=', 'grade_fees.fee_type_id')
            ->leftJoinSub($paidPerFee, 'p', 'p.enrollment_fee_id', '=', 'enrollment_fees.id')
            ->leftJoinSub($refundedPerFee, 'r', 'r.enrollment_fee_id', '=', 'enrollment_fees.id')
            ->select([
                'fee_types.id AS fee_type_id',
                'fee_types.name AS fee_type_name',
                'fee_types.system_key',
                DB::raw('SUM(enrollment_fees.amount_minor) AS charged_minor'),
                DB::raw('SUM(COALESCE(p.paid_minor, 0)) AS paid_minor'),
                DB::raw('SUM(COALESCE(r.refunded_minor, 0)) AS refunded_minor'),
            ])
            ->groupBy('fee_types.id', 'fee_types.name', 'fee_types.system_key')
            ->orderByRaw("CASE WHEN fee_types.system_key = 'tuition' THEN 0 ELSE 1 END")
            ->orderBy('fee_types.name')
            ->get();

        $feeTypes = [];
        $grand = 0;
        $current = 0;

        foreach ($rows as $row) {
            $isTuition = $row->system_key === 'tuition';
            $rowGrand = (int) $row->charged_minor - ($isTuition ? $discountTotal : 0);
            $rowCurrent = (int) $row->paid_minor - (int) $row->refunded_minor;
            $grand += $rowGrand;
            $current += $rowCurrent;

            $feeTypes[] = [
                'fee_type_id' => (int) $row->fee_type_id,
                'name' => (string) $row->fee_type_name,
                'is_tuition' => $isTuition,
                'grand_total' => $rowGrand,
                'current' => $rowCurrent,
                'remaining' => $rowGrand - $rowCurrent,
                'percent_paid' => $rowGrand > 0 ? ($rowCurrent / $rowGrand) * 100.0 : null,
            ];
        }

        return [
            'fee_types' => collect($feeTypes),
            'totals' => [
                'grand_total' => $grand,
                'current' => $current,
                'remaining' => $grand - $current,
                'percent_paid' => $grand > 0 ? ($current / $grand) * 100.0 : null,
            ],
        ];
    }

    /**
     * Active and suspended enrollments with owed > 0. The status filter
     * intersects with the charged statuses; a selection with no charged
     * status yields an empty list (never the unfiltered one).
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed}  $filters
     * @return Builder<Enrollment>
     */
    public static function callListQuery(array $filters = [], string $sort = 'owed_desc'): Builder
    {
        $f = FinancialSummary::filters($filters);
        $selected = $f['statuses'];
        $f['statuses'] = $selected === []
            ? FinancialSummary::CHARGED
            : array_values(array_intersect($selected, FinancialSummary::CHARGED));
        $forceEmpty = $selected !== [] && $f['statuses'] === [];

        $fees = DB::table('enrollment_fees')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS charged_minor'))
            ->groupBy('enrollment_id');

        $discounts = DB::table('enrollment_discounts')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS discount_minor'))
            ->groupBy('enrollment_id');

        $payments = DB::table('payments')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS paid_minor'))
            ->groupBy('enrollment_id');

        $refunds = DB::table('refunds')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS refunded_minor'))
            ->groupBy('enrollment_id');

        $owed = '(COALESCE(f.charged_minor, 0) - COALESCE(d.discount_minor, 0) - (COALESCE(p.paid_minor, 0) - COALESCE(r.refunded_minor, 0)))';

        return FinancialSummary::baseQuery($f)
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->join('grades', 'grades.id', '=', 'enrollments.grade_id')
            ->join('schools', 'schools.id', '=', 'grades.school_id')
            ->leftJoinSub($fees, 'f', 'f.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($discounts, 'd', 'd.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($payments, 'p', 'p.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($refunds, 'r', 'r.enrollment_id', '=', 'enrollments.id')
            ->select([
                'enrollments.id AS enrollment_id',
                'enrollments.status',
                'students.id AS student_id',
                'students.student_number',
                'students.name AS student_name',
                'students.phone',
                'students.secondary_phone',
                'students.guardian_name',
                'grades.number AS grade_number',
                'schools.name AS school_name',
                DB::raw("{$owed} AS owed_minor"),
            ])
            ->whereRaw($forceEmpty ? '0 = 1' : "{$owed} > 0")
            ->tap(fn (Builder $q) => self::applyCallListSort($q, $sort));
    }

    /** @param Builder<Enrollment> $query */
    private static function applyCallListSort(Builder $query, string $sort): void
    {
        match ($sort) {
            'name_asc' => $query->orderBy('students.name')->orderBy('students.id'),
            'name_desc' => $query->orderByDesc('students.name')->orderBy('students.id'),
            'owed_asc' => $query->orderBy('owed_minor')->orderBy('students.name'),
            default => $query->orderByDesc('owed_minor')->orderBy('students.name'),
        };
    }

    /**
     * The call list's count and owed total, aggregated in SQL. The page only
     * shows one page of rows, so loading every matching row to add them up
     * costs a full table read per request.
     *
     * @return array{count: int, owed: int}
     */
    public static function callListTotals(array $filters = []): array
    {
        $totals = DB::query()
            ->fromSub(self::callListQuery($filters), 'call_list')
            ->selectRaw('COUNT(*) AS rows_count, COALESCE(SUM(owed_minor), 0) AS owed_minor')
            ->first();

        return ['count' => (int) $totals->rows_count, 'owed' => (int) $totals->owed_minor];
    }

    /**
     * Forgone by leavers grouped by grade. Only ended enrollments with a
     * positive closed_without_payment appear.
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed}  $filters
     * @return EloquentCollection<int, Enrollment>
     */
    public static function forgoneByGrade(array $filters = []): EloquentCollection
    {
        $f = FinancialSummary::filters($filters);
        $f['statuses'] = $f['statuses'] === []
            ? FinancialSummary::ENDED
            : array_values(array_intersect($f['statuses'], FinancialSummary::ENDED));

        if ($f['statuses'] === []) {
            return new EloquentCollection;
        }

        $fees = DB::table('enrollment_fees')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS charged_minor'))
            ->groupBy('enrollment_id');

        $discounts = DB::table('enrollment_discounts')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS discount_minor'))
            ->groupBy('enrollment_id');

        $payments = DB::table('payments')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS paid_minor'))
            ->groupBy('enrollment_id');

        $refunds = DB::table('refunds')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS refunded_minor'))
            ->groupBy('enrollment_id');

        $closed = '(COALESCE(f.charged_minor, 0) - COALESCE(d.discount_minor, 0) - (COALESCE(p.paid_minor, 0) - COALESCE(r.refunded_minor, 0)))';

        return FinancialSummary::baseQuery($f)
            ->join('grades', 'grades.id', '=', 'enrollments.grade_id')
            ->join('schools', 'schools.id', '=', 'grades.school_id')
            ->leftJoinSub($fees, 'f', 'f.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($discounts, 'd', 'd.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($payments, 'p', 'p.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($refunds, 'r', 'r.enrollment_id', '=', 'enrollments.id')
            ->select([
                'grades.id AS grade_id',
                'grades.number AS grade_number',
                'schools.name AS school_name',
                DB::raw('COUNT(enrollments.id) AS students_count'),
                DB::raw("SUM({$closed}) AS forgone_minor"),
            ])
            ->groupBy('grades.id', 'grades.number', 'schools.name')
            ->havingRaw("SUM({$closed}) > 0")
            ->orderBy('schools.name')
            ->orderBy('grades.number')
            ->get();
    }

    /**
     * Forgone by leavers, one row per ended enrollment with a positive
     * closed_without_payment. Backs the details table under the
     * by-grade summary.
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed}  $filters
     * @return EloquentCollection<int, Enrollment>
     */
    public static function forgoneListQuery(array $filters = []): EloquentCollection
    {
        $f = FinancialSummary::filters($filters);
        $f['statuses'] = $f['statuses'] === []
            ? FinancialSummary::ENDED
            : array_values(array_intersect($f['statuses'], FinancialSummary::ENDED));

        if ($f['statuses'] === []) {
            return new EloquentCollection;
        }

        $fees = DB::table('enrollment_fees')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS charged_minor'))
            ->groupBy('enrollment_id');

        $discounts = DB::table('enrollment_discounts')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS discount_minor'))
            ->groupBy('enrollment_id');

        $payments = DB::table('payments')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS paid_minor'))
            ->groupBy('enrollment_id');

        $refunds = DB::table('refunds')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) AS refunded_minor'))
            ->groupBy('enrollment_id');

        $closed = '(COALESCE(f.charged_minor, 0) - COALESCE(d.discount_minor, 0) - (COALESCE(p.paid_minor, 0) - COALESCE(r.refunded_minor, 0)))';

        return FinancialSummary::baseQuery($f)
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->join('grades', 'grades.id', '=', 'enrollments.grade_id')
            ->join('schools', 'schools.id', '=', 'grades.school_id')
            ->leftJoinSub($fees, 'f', 'f.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($discounts, 'd', 'd.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($payments, 'p', 'p.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($refunds, 'r', 'r.enrollment_id', '=', 'enrollments.id')
            ->select([
                'enrollments.id AS enrollment_id',
                'enrollments.status',
                'students.id AS student_id',
                'students.student_number',
                'students.name AS student_name',
                'grades.number AS grade_number',
                'schools.name AS school_name',
                DB::raw("{$closed} AS forgone_minor"),
            ])
            ->whereRaw("{$closed} > 0")
            ->orderByDesc('forgone_minor')
            ->orderBy('students.name')
            ->get();
    }

    /**
     * Discounts given, grouped by snapshot name.
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed}  $filters
     * @return Collection<int, stdClass>
     */
    public static function discounts(array $filters = []): Collection
    {
        $ids = (clone FinancialSummary::baseQuery($filters))->select('enrollments.id');

        return DB::table('enrollment_discounts')
            ->whereIn('enrollment_id', $ids)
            ->select([
                'name',
                DB::raw('COUNT(DISTINCT enrollment_id) AS students_count'),
                DB::raw('SUM(amount_minor) AS total_amount_minor'),
            ])
            ->groupBy('name')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed, fee_type_id?: int|null, date_from?: string|null, date_to?: string|null}  $filters
     * @return Builder<Refund>
     */
    public static function refundsQuery(array $filters = []): Builder
    {
        $ids = (clone FinancialSummary::baseQuery($filters))->select('enrollments.id');

        $query = Refund::query()
            ->whereIn('refunds.enrollment_id', $ids)
            ->whereNull('refunds.voided_at')
            ->with([
                'enrollment.student:id,student_number,name',
                'enrollment.grade:id,school_id,number',
                'enrollment.grade.school:id,name',
                'enrollmentFee.gradeFee.feeType:id,name,system_key',
            ]);

        if (! empty($filters['fee_type_id'])) {
            $feeTypeId = (int) $filters['fee_type_id'];
            $query->whereHas('enrollmentFee.gradeFee', fn ($q) => $q->where('fee_type_id', $feeTypeId));
        }

        if (! empty($filters['date_from'])) {
            $query->where('refunds.refunded_on', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('refunds.refunded_on', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('refunds.refunded_on')->orderByDesc('refunds.id');
    }

    /**
     * The refund list's count and total, aggregated in SQL instead of
     * loading every matching refund row to add them up.
     *
     * @return array{count: int, amount: int}
     */
    public static function refundsTotals(array $filters = []): array
    {
        $totals = DB::query()
            ->fromSub(self::refundsQuery($filters), 'refunds_list')
            ->selectRaw('COUNT(*) AS rows_count, COALESCE(SUM(amount_minor), 0) AS refunded_minor')
            ->first();

        return ['count' => (int) $totals->rows_count, 'amount' => (int) $totals->refunded_minor];
    }

    /**
     * Collections grouped per day. One payment submission writes one row
     * per fee, so events are counted by distinct submission (enrollment,
     * date, method, transaction number, note) rather than by row.
     * Refunds appear as negative amounts; voided rows are audit-only.
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed, date_from?: string|null, date_to?: string|null}  $filters
     * @return array{days: Collection<int, array{date: string, cash: int, bank: int, refunds: int, net: int, events: int, txns: list<string>}>, total_cash: int, total_bank: int, total_refunds: int, net_total: int, events: int, voided_payments: Collection<int, stdClass>, voided_refunds: Collection<int, stdClass>}
     */
    public static function collections(array $filters = []): array
    {
        $ids = (clone FinancialSummary::baseQuery($filters))->select('enrollments.id');

        $payQuery = DB::table('payments')
            ->whereIn('enrollment_id', $ids)
            ->whereNull('voided_at');

        $refQuery = DB::table('refunds')
            ->whereIn('enrollment_id', $ids)
            ->whereNull('voided_at');

        $voidPayQuery = DB::table('payments')
            ->whereIn('enrollment_id', $ids)
            ->whereNotNull('voided_at');

        $voidRefQuery = DB::table('refunds')
            ->whereIn('enrollment_id', $ids)
            ->whereNotNull('voided_at');

        if (! empty($filters['date_from'])) {
            $payQuery->where('paid_on', '>=', $filters['date_from']);
            $refQuery->where('refunded_on', '>=', $filters['date_from']);
            $voidPayQuery->where('paid_on', '>=', $filters['date_from']);
            $voidRefQuery->where('refunded_on', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $payQuery->where('paid_on', '<=', $filters['date_to']);
            $refQuery->where('refunded_on', '<=', $filters['date_to']);
            $voidPayQuery->where('paid_on', '<=', $filters['date_to']);
            $voidRefQuery->where('refunded_on', '<=', $filters['date_to']);
        }

        $payments = $payQuery->orderBy('paid_on')->orderBy('id')->get();
        $refunds = $refQuery->orderBy('refunded_on')->orderBy('id')->get();

        $days = [];
        $totalCash = 0;
        $totalBank = 0;
        $totalRefunds = 0;
        $events = [];

        $day = function (string $date) use (&$days): array {
            if (! isset($days[$date])) {
                $days[$date] = ['date' => $date, 'cash' => 0, 'bank' => 0, 'refunds' => 0, 'net' => 0, 'events' => 0, 'txns' => []];
            }

            return $days[$date];
        };

        foreach ($payments as $p) {
            $date = (string) $p->paid_on;
            $row = $day($date);
            $amount = (int) $p->amount_minor;

            if ($p->method === PaymentMethod::Cash->value) {
                $row['cash'] += $amount;
                $totalCash += $amount;
            } else {
                $row['bank'] += $amount;
                $totalBank += $amount;

                if ($p->transaction_number !== null && $p->transaction_number !== '') {
                    $row['txns'][] = (string) $p->transaction_number;
                }
            }

            $key = implode('|', [(int) $p->enrollment_id, $date, (string) $p->method, (string) ($p->transaction_number ?? ''), (string) ($p->note ?? '')]);

            if (! isset($events[$key])) {
                $events[$key] = true;
                $row['events']++;
            }

            $days[$date] = $row;
        }

        foreach ($refunds as $r) {
            $date = (string) $r->refunded_on;
            $row = $day($date);
            $amount = (int) $r->amount_minor;
            $row['refunds'] -= $amount;
            $totalRefunds += $amount;

            if ($r->method !== PaymentMethod::Cash->value && $r->transaction_number !== null && $r->transaction_number !== '') {
                $row['txns'][] = (string) $r->transaction_number;
            }

            $key = implode('|', [(int) $r->enrollment_id, $date, (string) $r->method, (string) ($r->transaction_number ?? ''), (string) ($r->note ?? '')]);

            if (! isset($events[$key])) {
                $events[$key] = true;
                $row['events']++;
            }

            $days[$date] = $row;
        }

        ksort($days);

        foreach ($days as &$row) {
            $row['net'] = $row['cash'] + $row['bank'] + $row['refunds'];
            $row['txns'] = array_values(array_unique($row['txns']));
        }
        unset($row);

        return [
            'days' => collect(array_values($days)),
            'total_cash' => $totalCash,
            'total_bank' => $totalBank,
            'total_refunds' => $totalRefunds,
            'net_total' => $totalCash + $totalBank - $totalRefunds,
            'events' => count($events),
            'voided_payments' => $voidPayQuery->orderByDesc('voided_at')->get(),
            'voided_refunds' => $voidRefQuery->orderByDesc('voided_at')->get(),
        ];
    }

    /**
     * Net cash per month for the dashboard line chart.
     *
     * @return array<int, array{month: string, net_cash: int}>
     */
    public static function monthlyCollections(int $academicYearId): array
    {
        $ids = FinancialSummary::baseQuery(['academic_year_id' => $academicYearId])->select('enrollments.id');
        $driver = DB::connection()->getDriverName();
        $monthOf = $driver === 'sqlite' ? "strftime('%%Y-%%m', %s)" : "DATE_FORMAT(%s, '%%Y-%%m')";

        $paid = DB::table('payments')
            ->whereIn('enrollment_id', $ids)
            ->whereNull('voided_at')
            ->select([DB::raw(sprintf($monthOf, 'paid_on').' AS month_key'), DB::raw('SUM(amount_minor) AS total')])
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $refunded = DB::table('refunds')
            ->whereIn('enrollment_id', $ids)
            ->whereNull('voided_at')
            ->select([DB::raw(sprintf($monthOf, 'refunded_on').' AS month_key'), DB::raw('SUM(amount_minor) AS total')])
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $months = $paid->keys()->merge($refunded->keys())->unique()->sort()->values();
        $result = [];

        foreach ($months as $month) {
            $monthKey = (string) $month;

            if ($monthKey === '') {
                continue;
            }

            $result[] = [
                'month' => $monthKey,
                'net_cash' => (int) ($paid[$month] ?? 0) - (int) ($refunded[$month] ?? 0),
            ];
        }

        return $result;
    }
}
