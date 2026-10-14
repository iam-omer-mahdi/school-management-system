<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unified financial calculation layer for reports and the dashboard.
 * All calculations run in integer minor units using set-based SQL queries.
 *
 * Identities enforced:
 * - Grand total = Current (net paid) + Still owed + Forgone by leavers
 * - Current = Net paid = Paid - Refunded (voided rows excluded everywhere: voided_at IS NULL)
 * - Still owed = Owed on active and suspended enrollments only (charged - discount - net paid)
 * - Forgone by leavers = closed_without_payment on ended enrollments (left, expelled, transferred)
 * - % paid = Current / Grand total (dash or null when Grand total is 0)
 */
final class FinancialSummary
{
    /**
     * Build the base enrollment query with applied filters.
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: array<string>|null}  $filters
     * @return Builder<Enrollment>
     */
    public static function baseQuery(array $filters = []): Builder
    {
        $query = Enrollment::query();

        if (! empty($filters['academic_year_id'])) {
            $query->where('enrollments.academic_year_id', $filters['academic_year_id']);
        }

        if (! empty($filters['grade_id'])) {
            $query->where('enrollments.grade_id', $filters['grade_id']);
        } elseif (! empty($filters['school_id'])) {
            $query->whereHas('grade', function ($g) use ($filters): void {
                $g->where('school_id', $filters['school_id']);
            });
        }

        if (! empty($filters['statuses']) && is_array($filters['statuses'])) {
            $validStatuses = array_values(array_filter(
                $filters['statuses'],
                fn ($s) => EnrollmentStatus::tryFrom((string) $s) !== null
            ));
            if (! empty($validStatuses)) {
                $query->whereIn('enrollments.status', $validStatuses);
            }
        }

        return $query;
    }

    /**
     * Compute whole selection summary:
     * - count (total and by status)
     * - grand_total
     * - current (net_paid)
     * - remaining
     * - still_owed
     * - forgone
     * - paid_percentage
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: array<string>|null}  $filters
     * @return array{count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}
     */
    public static function forSelection(array $filters = []): array
    {
        $base = self::baseQuery($filters);

        // Pre-aggregate fees per enrollment
        $feesSub = DB::table('enrollment_fees')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as charged_minor'))
            ->groupBy('enrollment_id');

        // Pre-aggregate discounts per enrollment
        $discountsSub = DB::table('enrollment_discounts')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as discount_minor'))
            ->groupBy('enrollment_id');

        // Pre-aggregate non-voided payments per enrollment
        $paymentsSub = DB::table('payments')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as paid_minor'))
            ->groupBy('enrollment_id');

        // Pre-aggregate non-voided refunds per enrollment
        $refundsSub = DB::table('refunds')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as refunded_minor'))
            ->groupBy('enrollment_id');

        $enrollmentRows = (clone $base)
            ->leftJoinSub($feesSub, 'f', 'f.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($discountsSub, 'd', 'd.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($paymentsSub, 'p', 'p.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($refundsSub, 'r', 'r.enrollment_id', '=', 'enrollments.id')
            ->select([
                'enrollments.id',
                'enrollments.status',
                DB::raw('COALESCE(f.charged_minor, 0) as charged_minor'),
                DB::raw('COALESCE(d.discount_minor, 0) as discount_minor'),
                DB::raw('COALESCE(p.paid_minor, 0) as paid_minor'),
                DB::raw('COALESCE(r.refunded_minor, 0) as refunded_minor'),
            ]);

        $statusCounts = [];
        foreach (EnrollmentStatus::cases() as $case) {
            $statusCounts[$case->value] = 0;
        }

        $grandTotal = 0;
        $current = 0;
        $stillOwed = 0;
        $forgone = 0;
        $count = 0;

        foreach ($enrollmentRows->cursor() as $row) {
            $count++;
            $status = (string) $row->status;
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }

            $c = (int) $row->charged_minor;
            $d = (int) $row->discount_minor;
            $p = (int) $row->paid_minor;
            $r = (int) $row->refunded_minor;

            $netPaid = $p - $r;
            $netCharge = $c - $d;
            $isEnded = in_array($status, ['transferred', 'expelled', 'left'], true);

            $grandTotal += $netCharge;
            $current += $netPaid;

            if ($isEnded) {
                $forgone += ($netCharge - $netPaid);
            } else {
                $stillOwed += ($netCharge - $netPaid);
            }
        }

        $remaining = $grandTotal - $current;
        $percentPaid = $grandTotal > 0 ? ($current / $grandTotal) * 100 : null;

        return [
            'count' => $count,
            'by_status' => $statusCounts,
            'grand_total' => $grandTotal,
            'current' => $current,
            'remaining' => $remaining,
            'still_owed' => $stillOwed,
            'forgone' => $forgone,
            'percent_paid' => $percentPaid,
        ];
    }

    /**
     * Compute Year Summary grouped by Grade, including whole school totals row.
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: array<string>|null}  $filters
     * @return array{grades: Collection<int, array{grade_id: int, grade_number: int, school_id: int, school_code: string, count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}>, totals: array{count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}}
     */
    public static function yearSummary(array $filters = []): array
    {
        $base = self::baseQuery($filters);

        // Pre-aggregate fees per enrollment
        $feesSub = DB::table('enrollment_fees')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as charged_minor'))
            ->groupBy('enrollment_id');

        // Pre-aggregate discounts per enrollment
        $discountsSub = DB::table('enrollment_discounts')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as discount_minor'))
            ->groupBy('enrollment_id');

        // Pre-aggregate payments per enrollment
        $paymentsSub = DB::table('payments')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as paid_minor'))
            ->groupBy('enrollment_id');

        // Pre-aggregate refunds per enrollment
        $refundsSub = DB::table('refunds')
            ->whereNull('voided_at')
            ->select('enrollment_id', DB::raw('SUM(amount_minor) as refunded_minor'))
            ->groupBy('enrollment_id');

        $rows = (clone $base)
            ->join('grades', 'grades.id', '=', 'enrollments.grade_id')
            ->join('schools', 'schools.id', '=', 'grades.school_id')
            ->leftJoinSub($feesSub, 'f', 'f.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($discountsSub, 'd', 'd.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($paymentsSub, 'p', 'p.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($refundsSub, 'r', 'r.enrollment_id', '=', 'enrollments.id')
            ->select([
                'enrollments.id',
                'enrollments.grade_id',
                'grades.number as grade_number',
                'grades.school_id',
                'schools.code as school_code',
                'enrollments.status',
                DB::raw('COALESCE(f.charged_minor, 0) as charged_minor'),
                DB::raw('COALESCE(d.discount_minor, 0) as discount_minor'),
                DB::raw('COALESCE(p.paid_minor, 0) as paid_minor'),
                DB::raw('COALESCE(r.refunded_minor, 0) as refunded_minor'),
            ])
            ->orderBy('grades.school_id')
            ->orderBy('grades.number');

        $gradesMap = [];

        $totalCount = 0;
        $totalByStatus = [];
        foreach (EnrollmentStatus::cases() as $case) {
            $totalByStatus[$case->value] = 0;
        }
        $totalGrandTotal = 0;
        $totalCurrent = 0;
        $totalStillOwed = 0;
        $totalForgone = 0;

        foreach ($rows->cursor() as $row) {
            $gid = (int) $row->grade_id;
            if (! isset($gradesMap[$gid])) {
                $statusInit = [];
                foreach (EnrollmentStatus::cases() as $case) {
                    $statusInit[$case->value] = 0;
                }
                $gradesMap[$gid] = [
                    'grade_id' => $gid,
                    'grade_number' => (int) $row->grade_number,
                    'school_id' => (int) $row->school_id,
                    'school_code' => (string) $row->school_code,
                    'count' => 0,
                    'by_status' => $statusInit,
                    'grand_total' => 0,
                    'current' => 0,
                    'remaining' => 0,
                    'still_owed' => 0,
                    'forgone' => 0,
                    'percent_paid' => null,
                ];
            }

            $status = (string) $row->status;
            $gradesMap[$gid]['count']++;
            $totalCount++;
            if (isset($gradesMap[$gid]['by_status'][$status])) {
                $gradesMap[$gid]['by_status'][$status]++;
            }
            if (isset($totalByStatus[$status])) {
                $totalByStatus[$status]++;
            }

            $c = (int) $row->charged_minor;
            $d = (int) $row->discount_minor;
            $p = (int) $row->paid_minor;
            $r = (int) $row->refunded_minor;

            $netPaid = $p - $r;
            $netCharge = $c - $d;
            $isEnded = in_array($status, ['transferred', 'expelled', 'left'], true);

            $gradesMap[$gid]['grand_total'] += $netCharge;
            $gradesMap[$gid]['current'] += $netPaid;
            $totalGrandTotal += $netCharge;
            $totalCurrent += $netPaid;

            if ($isEnded) {
                $gradesMap[$gid]['forgone'] += ($netCharge - $netPaid);
                $totalForgone += ($netCharge - $netPaid);
            } else {
                $gradesMap[$gid]['still_owed'] += ($netCharge - $netPaid);
                $totalStillOwed += ($netCharge - $netPaid);
            }
        }

        foreach ($gradesMap as &$gradeData) {
            $gradeData['remaining'] = $gradeData['grand_total'] - $gradeData['current'];
            $gradeData['percent_paid'] = $gradeData['grand_total'] > 0
                ? ($gradeData['current'] / $gradeData['grand_total']) * 100
                : null;
        }
        unset($gradeData);

        $totalRemaining = $totalGrandTotal - $totalCurrent;
        $totalPercentPaid = $totalGrandTotal > 0 ? ($totalCurrent / $totalGrandTotal) * 100 : null;

        return [
            'grades' => collect(array_values($gradesMap)),
            'totals' => [
                'count' => $totalCount,
                'by_status' => $totalByStatus,
                'grand_total' => $totalGrandTotal,
                'current' => $totalCurrent,
                'remaining' => $totalRemaining,
                'still_owed' => $totalStillOwed,
                'forgone' => $totalForgone,
                'percent_paid' => $totalPercentPaid,
            ],
        ];
    }

    /**
     * Tuition vs other fees by fee type.
     * Note: Discounts reduce tuition only.
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: array<string>|null}  $filters
     * @return array{fee_types: Collection<int, array{fee_type_id: int, name: string, is_tuition: bool, grand_total: int, current: int, remaining: int, percent_paid: float|null}>, totals: array{grand_total: int, current: int, remaining: int, percent_paid: float|null}}
     */
    public static function byFeeType(array $filters = []): array
    {
        $base = self::baseQuery($filters);
        $enrollmentIds = (clone $base)->select('enrollments.id');

        // Total tuition discounts for the selection
        $totalDiscount = (int) DB::table('enrollment_discounts')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->sum('amount_minor');

        // Payments per enrollment_fee
        $paymentsSub = DB::table('payments')
            ->whereNull('voided_at')
            ->select('enrollment_fee_id', DB::raw('SUM(amount_minor) as paid_minor'))
            ->groupBy('enrollment_fee_id');

        // Refunds per enrollment_fee
        $refundsSub = DB::table('refunds')
            ->whereNull('voided_at')
            ->select('enrollment_fee_id', DB::raw('SUM(amount_minor) as refunded_minor'))
            ->groupBy('enrollment_fee_id');

        $rows = DB::table('enrollment_fees')
            ->whereIn('enrollment_fees.enrollment_id', $enrollmentIds)
            ->join('grade_fees', 'grade_fees.id', '=', 'enrollment_fees.grade_fee_id')
            ->join('fee_types', 'fee_types.id', '=', 'grade_fees.fee_type_id')
            ->leftJoinSub($paymentsSub, 'p', 'p.enrollment_fee_id', '=', 'enrollment_fees.id')
            ->leftJoinSub($refundsSub, 'r', 'r.enrollment_fee_id', '=', 'enrollment_fees.id')
            ->select([
                'fee_types.id as fee_type_id',
                'fee_types.name as fee_type_name',
                'fee_types.system_key',
                DB::raw('SUM(enrollment_fees.amount_minor) as charged_minor'),
                DB::raw('SUM(COALESCE(p.paid_minor, 0)) as paid_minor'),
                DB::raw('SUM(COALESCE(r.refunded_minor, 0)) as refunded_minor'),
            ])
            ->groupBy('fee_types.id', 'fee_types.name', 'fee_types.system_key')
            ->orderByRaw("CASE WHEN fee_types.system_key = 'tuition' THEN 0 ELSE 1 END")
            ->orderBy('fee_types.name')
            ->get();

        $feeTypes = [];
        $totalGrand = 0;
        $totalCurrent = 0;

        foreach ($rows as $row) {
            $isTuition = $row->system_key === 'tuition';
            $charged = (int) $row->charged_minor;
            $discount = $isTuition ? $totalDiscount : 0;
            $grand = $charged - $discount;

            $paid = (int) $row->paid_minor;
            $refunded = (int) $row->refunded_minor;
            $netPaid = $paid - $refunded;

            $remaining = $grand - $netPaid;
            $percent = $grand > 0 ? ($netPaid / $grand) * 100 : null;

            $totalGrand += $grand;
            $totalCurrent += $netPaid;

            $feeTypes[] = [
                'fee_type_id' => (int) $row->fee_type_id,
                'name' => (string) $row->fee_type_name,
                'is_tuition' => $isTuition,
                'grand_total' => $grand,
                'current' => $netPaid,
                'remaining' => $remaining,
                'percent_paid' => $percent,
            ];
        }

        $totalRemaining = $totalGrand - $totalCurrent;
        $totalPercent = $totalGrand > 0 ? ($totalCurrent / $totalGrand) * 100 : null;

        return [
            'fee_types' => collect($feeTypes),
            'totals' => [
                'grand_total' => $totalGrand,
                'current' => $totalCurrent,
                'remaining' => $totalRemaining,
                'percent_paid' => $totalPercent,
            ],
        ];
    }

    /**
     * Monthly collections for a year (line chart data on dashboard).
     *
     * @return array<int, array{month: string, net_cash: int}>
     */
    public static function monthlyCollections(int $academicYearId): array
    {
        $enrollmentIds = Enrollment::query()
            ->where('academic_year_id', $academicYearId)
            ->select('id');

        $paidRows = DB::table('payments')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNull('voided_at')
            ->select([
                DB::raw("strftime('%Y-%m', paid_on) as month_key"),
                DB::raw('SUM(amount_minor) as total_paid'),
            ])
            ->groupBy('month_key')
            ->pluck('total_paid', 'month_key');

        $refundRows = DB::table('refunds')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNull('voided_at')
            ->select([
                DB::raw("strftime('%Y-%m', refunded_on) as month_key"),
                DB::raw('SUM(amount_minor) as total_refunded'),
            ])
            ->groupBy('month_key')
            ->pluck('total_refunded', 'month_key');

        // Compatible with MySQL and SQLite (strftime / DATE_FORMAT fallback)
        // If MySQL, strftime returns null, so let's handle database driver
        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'sqlite' ? "strftime('%Y-%m', %s)" : "DATE_FORMAT(%s, '%Y-%m')";

        $paidQuery = DB::table('payments')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNull('voided_at')
            ->select([
                DB::raw(sprintf($dateExpr, 'paid_on').' as month_key'),
                DB::raw('SUM(amount_minor) as total_paid'),
            ])
            ->groupBy('month_key')
            ->pluck('total_paid', 'month_key');

        $refundQuery = DB::table('refunds')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNull('voided_at')
            ->select([
                DB::raw(sprintf($dateExpr, 'refunded_on').' as month_key'),
                DB::raw('SUM(amount_minor) as total_refunded'),
            ])
            ->groupBy('month_key')
            ->pluck('total_refunded', 'month_key');

        $allMonths = $paidQuery->keys()->merge($refundQuery->keys())->unique()->sort()->values();

        $result = [];
        foreach ($allMonths as $month) {
            if ($month === null || $month === '') {
                continue;
            }
            $p = (int) ($paidQuery[$month] ?? 0);
            $r = (int) ($refundQuery[$month] ?? 0);
            $result[] = [
                'month' => (string) $month,
                'net_cash' => $p - $r,
            ];
        }

        return $result;
    }
}
