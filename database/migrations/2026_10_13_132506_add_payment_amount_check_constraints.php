<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Database-level guards for payment money rules. MySQL 8.4 enforces
     * CHECK constraints; application validation mirrors each of them.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_kind CHECK (kind IN ('fee', 'overpayment'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_kind_fee CHECK ((kind = \'fee\') = (enrollment_fee_id IS NOT NULL))');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_method CHECK (method IN ('cash', 'bank_transfer'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT chk_refunds_amount CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT chk_refunds_kind CHECK (kind IN ('overpayment'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT chk_refunds_method CHECK (method IN ('cash', 'bank_transfer'))");
    }

    public function down(): void
    {
        // DROP CONSTRAINT drops named CHECKs on MySQL >= 8.0.19 and on
        // MariaDB, so one statement covers both engines.
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT chk_refunds_method');
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT chk_refunds_kind');
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT chk_refunds_amount');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_method');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_kind_fee');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_kind');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_amount');
    }
};
