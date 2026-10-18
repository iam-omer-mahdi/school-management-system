<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\PaymentMethod;
use App\Models\Refund;
use App\Models\Student;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Column/row builders for the Excel download of each report tab.
 * Returns plain arrays; the page component adds the meta header rows
 * and streams through ExcelExport. Percent columns carry 0-1 ratios
 * (PCT format), money carries minor/100 floats (MONEY format).
 */
final class ReportExport
{
    /**
     * @param  array{grades: Collection<int, array{grade_id: int, grade_number: int, school_id: int, school_name: string, count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}>, totals: array{count: int, by_status: array<string, int>, grand_total: int, current: int, remaining: int, still_owed: int, forgone: int, percent_paid: float|null}}  $summary
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>, formats: array<int, string>}
     */
    public static function yearSummary(array $summary): array
    {
        $columns = [
            __('fees.school'), __('fees.grade'), __('reports.enrollments_count'),
            __('reports.grand_total'), __('reports.current'), __('reports.remaining'),
            __('reports.still_owed'), __('reports.forgone'), __('reports.percent_paid'),
        ];
        $formats = [
            ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::INT,
            ExcelExport::MONEY, ExcelExport::MONEY, ExcelExport::MONEY,
            ExcelExport::MONEY, ExcelExport::MONEY, ExcelExport::PCT,
        ];
        $rows = [];

        foreach ($summary['grades'] as $g) {
            $rows[] = [
                $g['school_name'],
                __('schools.grade_number', ['number' => $g['grade_number']]),
                $g['count'],
                ExcelExport::money($g['grand_total']),
                ExcelExport::money($g['current']),
                ExcelExport::money($g['remaining']),
                ExcelExport::money($g['still_owed']),
                ExcelExport::money($g['forgone']),
                $g['percent_paid'] !== null ? round($g['percent_paid'] / 100, 4) : null,
            ];
        }

        $t = $summary['totals'];
        $rows[] = [
            __('payments.total'), '', $t['count'],
            ExcelExport::money($t['grand_total']),
            ExcelExport::money($t['current']),
            ExcelExport::money($t['remaining']),
            ExcelExport::money($t['still_owed']),
            ExcelExport::money($t['forgone']),
            $t['percent_paid'] !== null ? round($t['percent_paid'] / 100, 4) : null,
        ];

        return ['columns' => $columns, 'rows' => $rows, 'formats' => $formats];
    }

    /**
     * @param  array{fee_types: Collection<int, array{fee_type_id: int, name: string, is_tuition: bool, grand_total: int, current: int, remaining: int, percent_paid: float|null}>, totals: array{grand_total: int, current: int, remaining: int, percent_paid: float|null}}  $data
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>, formats: array<int, string>}
     */
    public static function feeTypes(array $data): array
    {
        $columns = [
            __('reports.fee_type'), __('reports.grand_total'), __('reports.current'),
            __('reports.remaining'), __('reports.percent_paid'),
        ];
        $formats = [ExcelExport::TEXT, ExcelExport::MONEY, ExcelExport::MONEY, ExcelExport::MONEY, ExcelExport::PCT];
        $rows = [];

        foreach ($data['fee_types'] as $ft) {
            $rows[] = [
                $ft['is_tuition'] ? __('fees.tuition') : $ft['name'],
                ExcelExport::money($ft['grand_total']),
                ExcelExport::money($ft['current']),
                ExcelExport::money($ft['remaining']),
                $ft['percent_paid'] !== null ? round($ft['percent_paid'] / 100, 4) : null,
            ];
        }

        $t = $data['totals'];
        $rows[] = [
            __('payments.total'),
            ExcelExport::money($t['grand_total']),
            ExcelExport::money($t['current']),
            ExcelExport::money($t['remaining']),
            $t['percent_paid'] !== null ? round($t['percent_paid'] / 100, 4) : null,
        ];

        return ['columns' => $columns, 'rows' => $rows, 'formats' => $formats];
    }

    /**
     * @param  iterable<stdClass>  $items
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>, formats: array<int, string>}
     */
    public static function callList(iterable $items): array
    {
        $columns = [
            __('students.student_number'), __('students.name'), __('students.phone'),
            __('students.secondary_phone'), __('students.guardian_name'),
            __('fees.grade'), __('enrollments.status'), __('reports.still_owed'),
        ];
        $formats = [
            ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::TEXT,
            ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::MONEY,
        ];
        $rows = [];

        foreach ($items as $item) {
            $status = $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status;
            $rows[] = [
                $item->student_number, $item->student_name, $item->phone,
                $item->secondary_phone ?? '', $item->guardian_name ?? '',
                $item->school_name.' '.__('schools.grade_number', ['number' => $item->grade_number]),
                __('enrollments.status_'.$status),
                ExcelExport::money((int) $item->owed_minor),
            ];
        }

        return ['columns' => $columns, 'rows' => $rows, 'formats' => $formats];
    }

    /**
     * @param  array{days: Collection<int, array{date: string, cash: int, bank: int, refunds: int, net: int, events: int, txns: list<string>}>, total_cash: int, total_bank: int, total_refunds: int, net_total: int, events: int, voided_payments: Collection<int, stdClass>, voided_refunds: Collection<int, stdClass>}  $data
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>, formats: array<int, string>}
     */
    public static function collections(array $data): array
    {
        $columns = [
            __('payments.paid_on'), __('payments.cash'), __('payments.bank_transfer'),
            __('reports.refunds'), __('reports.transaction_numbers'),
            __('reports.events_count'), __('reports.cash_net'),
        ];
        $formats = [
            ExcelExport::DATE, ExcelExport::MONEY, ExcelExport::MONEY, ExcelExport::MONEY,
            ExcelExport::TEXT, ExcelExport::INT, ExcelExport::MONEY,
        ];
        $rows = [];

        foreach ($data['days'] as $day) {
            $rows[] = [
                $day['date'],
                ExcelExport::money($day['cash']),
                ExcelExport::money($day['bank']),
                ExcelExport::money($day['refunds']),
                implode(', ', $day['txns']),
                $day['events'],
                ExcelExport::money($day['net']),
            ];
        }

        $rows[] = [
            __('payments.total'),
            ExcelExport::money($data['total_cash']),
            ExcelExport::money($data['total_bank']),
            ExcelExport::money(-$data['total_refunds']),
            '', $data['events'],
            ExcelExport::money($data['net_total']),
        ];

        return ['columns' => $columns, 'rows' => $rows, 'formats' => $formats];
    }

    /**
     * @param  iterable<stdClass>  $items
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>, formats: array<int, string>}
     */
    public static function discounts(iterable $items): array
    {
        $columns = [__('reports.discount_name'), __('reports.students_count'), __('reports.total_amount')];
        $formats = [ExcelExport::TEXT, ExcelExport::INT, ExcelExport::MONEY];
        $rows = [];

        foreach ($items as $d) {
            $rows[] = [$d->name, (int) $d->students_count, ExcelExport::money((int) $d->total_amount_minor)];
        }

        return ['columns' => $columns, 'rows' => $rows, 'formats' => $formats];
    }

    /**
     * Per-student forgone details with plain names (no links in Excel).
     *
     * @param  iterable<stdClass>  $items
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>, formats: array<int, string>}
     */
    public static function forgone(iterable $items): array
    {
        $columns = [
            __('students.student_number'), __('students.name'), __('fees.grade'),
            __('enrollments.status'), __('reports.forgone'),
        ];
        $formats = [ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::MONEY];
        $rows = [];
        $total = 0;

        foreach ($items as $f) {
            $status = $f->status instanceof \BackedEnum ? $f->status->value : (string) $f->status;
            $total += (int) $f->forgone_minor;
            $rows[] = [
                $f->student_number,
                $f->student_name,
                $f->school_name.' '.__('schools.grade_number', ['number' => $f->grade_number]),
                __('enrollments.status_'.$status),
                ExcelExport::money((int) $f->forgone_minor),
            ];
        }

        $rows[] = [__('payments.total'), '', '', '', ExcelExport::money($total)];

        return ['columns' => $columns, 'rows' => $rows, 'formats' => $formats];
    }

    /**
     * @param  iterable<Refund>  $items
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>, formats: array<int, string>}
     */
    public static function refunds(iterable $items): array
    {
        $columns = [
            __('reports.refunded_on'), __('students.student_number'), __('students.name'),
            __('fees.grade'), __('reports.fee_type'), __('enrollments.status'),
            __('payments.method'), __('payments.amount'), __('reports.note'),
        ];
        $formats = [
            ExcelExport::DATE, ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::TEXT,
            ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::MONEY, ExcelExport::TEXT,
        ];
        $rows = [];

        foreach ($items as $r) {
            $feeType = $r->enrollmentFee->gradeFee->feeType;
            $rows[] = [
                $r->refunded_on?->format('Y-m-d') ?? '',
                $r->enrollment->student->student_number,
                $r->enrollment->student->name,
                $r->enrollment->grade->school->name.' '.__('schools.grade_number', ['number' => $r->enrollment->grade->number]),
                $feeType->isTuition() ? __('fees.tuition') : $feeType->name,
                __('enrollments.status_'.$r->enrollment->status->value),
                $r->method === PaymentMethod::Cash ? __('payments.cash') : __('payments.bank_transfer'),
                ExcelExport::money($r->amount_minor),
                $r->note,
            ];
        }

        return ['columns' => $columns, 'rows' => $rows, 'formats' => $formats];
    }

    /**
     * @param  array<int, array{charged: int, discount: int, net_paid: int}>  $balances
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>, formats: array<int, string>}
     */
    public static function statement(Student $student, array $balances): array
    {
        $columns = [
            __('fees.academic_year'), __('fees.grade'), __('enrollments.status'),
            __('reports.grand_total'), __('reports.current'), __('reports.remaining'),
        ];
        $formats = [
            ExcelExport::TEXT, ExcelExport::TEXT, ExcelExport::TEXT,
            ExcelExport::MONEY, ExcelExport::MONEY, ExcelExport::MONEY,
        ];
        $rows = [];

        foreach ($student->enrollments as $enr) {
            $bal = $balances[$enr->id];
            $rows[] = [
                $enr->academicYear->name,
                $enr->grade->school->name.' '.__('schools.grade_number', ['number' => $enr->grade->number]),
                __('enrollments.status_'.$enr->status->value),
                ExcelExport::money($bal['charged'] - $bal['discount']),
                ExcelExport::money($bal['net_paid']),
                ExcelExport::money($bal['charged'] - $bal['discount'] - $bal['net_paid']),
            ];
        }

        return ['columns' => $columns, 'rows' => $rows, 'formats' => $formats];
    }
}
