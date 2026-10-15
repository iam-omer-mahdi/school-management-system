<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Reports\ExcelExport;
use Tests\TestCase;

class ExcelExportTest extends TestCase
{
    public function test_money_converts_minor_units_in_one_place(): void
    {
        $this->assertSame(1500.0, ExcelExport::money(150000));
        $this->assertSame(0.05, ExcelExport::money(5));
    }

    public function test_escape_prefixes_formula_starts(): void
    {
        foreach (['=SUM(A1)', '+cmd', '-2+3', '@mention', ' normal'] as $value) {
            $escaped = ExcelExport::escape($value);

            if (in_array($value[0], ['=', '+', '-', '@'], true)) {
                $this->assertSame("'".$value, $escaped);
            } else {
                $this->assertSame($value, $escaped);
            }
        }

        $this->assertSame('', ExcelExport::escape(null));
        $this->assertSame('', ExcelExport::escape(''));
    }

    public function test_date_serial_uses_excel_epoch(): void
    {
        $this->assertSame(0.0, ExcelExport::dateSerial('1899-12-30'));
        $this->assertSame(2.0, ExcelExport::dateSerial('1900-01-01'));
        $this->assertGreaterThan(45000, ExcelExport::dateSerial('2026-01-01'));
    }
}
