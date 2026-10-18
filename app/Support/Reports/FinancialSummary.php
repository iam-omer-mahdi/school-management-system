<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single set-based SQL query layer for every report and the dashboard.
 * All money stays in integer minor units. Voided payments and refunds
 * are excluded everywhere (voided_at IS NULL).
 *
 * Definitions mirror EnrollmentBalance exactly (raw formulas, no
 * clamping — overpayment is impossible through the guarded Actions):
 * - grand total = charged - discount, every status in the selection
 * - current = net paid = paid - refunded
 * - remaining = grand total - current
 * - still owed = owed on active and suspended enrollments only
 * - forgone = closed_without_payment on ended enrollments only
 * - % paid = current / grand total (null when grand total is 0)
 *
 * Identity: grand total = current + still owed + forgone.
 */
final class FinancialSummary
{
    public const CHARGED = ['active', 'suspended'];

    public const ENDED = ['left', 'expelled', 'transferred'];

    /**
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed}  $filters
     * @return array{academic_year_id: int|null, school_id: int|null, grade_id: int|null, statuses: array<string>}
     */
    public static function filters(array $filters = []): array
    {
        $statuses = $filters['statuses'] ?? [];
        $statuses = is_array($statuses) ? array_values(array_unique(array_filter(
            array_map(strval(...), $statuses),
            fn (string $s): bool => EnrollmentStatus::tryFrom($s) !== null
        ))) : [];

        return [
            'academic_year_id' => isset($filters['academic_year_id']) ? (int) $filters['academic_year_id'] : null,
            'school_id' => isset($filters['school_id']) ? (int) $filters['school_id'] : null,
            'grade_id' => isset($filters['grade_id']) ? (int) $filters['grade_id'] : null,
            'statuses' => $statuses,
        ];
    }

    /**
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed}  $filters
     * @return Builder<Enrollment>
     */
    public static function baseQuery(array $filters = []): Builder
    {
        $f = self::filters($filters);
        $query = Enrollment::query();

        if ($f['academic_year_id'] !== null) {
            $query->where('enrollments.academic_year_id', $f['academic_year_id']);
        }

        if ($f['grade_id'] !== null) {
            $query->where('enrollments.grade_id', $f['grade_id']);
        }

        if ($f['school_id'] !== null) {
            $query->whereHas('grade', fn ($g) => $g->where('school_id', $f['school_id']));
        }

        if ($f['statuses'] !== []) {
            $query->whereIn('enrollments.status', $f['statuses']);
        }

        return $query;
    }

    /** @return array<string, int> */
    public static function emptyStatusCounts(): array
    {
        $counts = [];

        foreach (EnrollmentStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        return $counts;
    }

    public static function isEnded(string $status): bool
    {
        return in_array($status, self::ENDED, true);
    }

    /**
     * One row per enrollment with charged/discount/paid/refunded sums.
     * Joins grade + school so callers can group without extra queries.
     *
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed}  $filters
     * @return iterable<Enrollment>
     */
    private static function rows(array $filters = []): iterable
    {
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

        return self::baseQuery($filters)
            ->join('grades', 'grades.id', '=', 'enrollments.grade_id')
            ->join('schools', 'schools.id', '=', 'grades.school_id')
            ->leftJoinSub($fees, 'f', 'f.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($discounts, 'd', 'd.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($payments, 'p', 'p.enrollment_id', '=', 'enrollments.id')
            ->leftJoinSub($refunds, 'r', 'r.enrollment_id', '=', 'enrollments.id')
            ->select([
                'enrollments.id',
                'enrollments.status',
                'enrollments.grade_id',
                'grades.number AS grade_number',
                'grades.school_id',
                'schools.name AS school_name',
                DB::raw('COALESCE(f.charged_minor, 0) AS charged_minor'),
                DB::raw('COALESCE(d.discount_minor, 0) AS discount_minor'),
                DB::raw('COALESCE(p.paid_minor, 0) AS paid_minor'),
                DB::raw('COALESCE(r.refunded_minor, 0) AS refunded_minor'),
            ])
            ->orderBy('schools.id')
            ->orderBy('grades.number')
            ->cursor();
    }

    /**
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed}  $filters
     * @return array{count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}
     */
    public static function forSelection(array $filters = []): array
    {
        $total = self::blankTotals();

        foreach (self::rows($filters) as $row) {
            self::accumulate($total, $row);
        }

        return self::finalize($total);
    }

    /**
     * @param  array{academic_year_id?: int|null, school_id?: int|null, grade_id?: int|null, statuses?: mixed}  $filters
     * @return array{grades: Collection<int, array{grade_id: int, grade_number: int, school_id: int, school_name: string, count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}>, totals: array{count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}}
     */
    public static function yearSummary(array $filters = []): array
    {
        $gradeMeta = [];
        $gradeTotals = [];
        $totals = self::blankTotals();

        foreach (self::rows($filters) as $row) {
            $gid = (int) $row->grade_id;

            if (! isset($gradeTotals[$gid])) {
                $gradeMeta[$gid] = [
                    'grade_id' => $gid,
                    'grade_number' => (int) $row->getAttribute('grade_number'),
                    'school_id' => (int) $row->getAttribute('school_id'),
                    'school_name' => (string) $row->getAttribute('school_name'),
                ];
                $gradeTotals[$gid] = self::blankTotals();
            }

            self::accumulate($gradeTotals[$gid], $row);
            self::accumulate($totals, $row);
        }

        $grades = [];

        foreach ($gradeTotals as $gid => $gradeTotal) {
            $grades[] = array_merge($gradeMeta[$gid], self::finalize($gradeTotal));
        }

        return ['grades' => collect($grades), 'totals' => self::finalize($totals)];
    }

    /**
     * @param  array{count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}  $total
     */
    private static function accumulate(array &$total, Enrollment $row): void
    {
        $status = $row->status->value;
        $charged = (int) $row->getAttribute('charged_minor');
        $discount = (int) $row->getAttribute('discount_minor');
        $netPaid = (int) $row->getAttribute('paid_minor') - (int) $row->getAttribute('refunded_minor');
        $netCharge = $charged - $discount;
        $balance = $netCharge - $netPaid;

        $total['count']++;
        $total['by_status'][$status] = ($total['by_status'][$status] ?? 0) + 1;
        $total['grand_total'] += $netCharge;
        $total['current'] += $netPaid;

        if (self::isEnded($status)) {
            $total['forgone'] += $balance;
        } else {
            $total['still_owed'] += $balance;
        }
    }

    /** @return array{count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null} */
    private static function blankTotals(): array
    {
        return [
            'count' => 0,
            'by_status' => self::emptyStatusCounts(),
            'grand_total' => 0,
            'current' => 0,
            'remaining' => 0,
            'still_owed' => 0,
            'forgone' => 0,
            'percent_paid' => null,
        ];
    }

    /**
     * @param  array{count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}  $total
     * @return array{count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}
     */
    private static function finalize(array $total): array
    {
        $total['remaining'] = $total['grand_total'] - $total['current'];
        $total['percent_paid'] = $total['grand_total'] > 0
            ? ($total['current'] / $total['grand_total']) * 100.0
            : null;

        return $total;
    }
}
