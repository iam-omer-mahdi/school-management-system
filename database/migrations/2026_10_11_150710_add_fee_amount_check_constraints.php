<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Database-level guards for fee money rules. MySQL 8.4 enforces
     * CHECK constraints; application validation mirrors each of them.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE grade_fees ADD CONSTRAINT chk_grade_fees_amount CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE enrollment_fees ADD CONSTRAINT chk_enrollment_fees_amount CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE enrollment_discounts ADD CONSTRAINT chk_enrollment_discounts_amount CHECK (amount_minor >= 0)');
        DB::statement('ALTER TABLE enrollment_discounts ADD CONSTRAINT chk_enrollment_discounts_percent CHECK (percent_bp BETWEEN 1 AND 10000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE enrollment_discounts DROP CHECK chk_enrollment_discounts_percent');
        DB::statement('ALTER TABLE enrollment_discounts DROP CHECK chk_enrollment_discounts_amount');
        DB::statement('ALTER TABLE enrollment_fees DROP CHECK chk_enrollment_fees_amount');
        DB::statement('ALTER TABLE grade_fees DROP CHECK chk_grade_fees_amount');
    }
};
