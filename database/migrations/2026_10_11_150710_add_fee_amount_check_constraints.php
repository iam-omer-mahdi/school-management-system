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
        DB::statement('ALTER TABLE discounts ADD CONSTRAINT chk_discounts_percent CHECK (percent_bp BETWEEN 1 AND 10000)');
        DB::statement('ALTER TABLE enrollment_discounts ADD CONSTRAINT chk_enrollment_discounts_amount CHECK (amount_minor >= 0)');
        DB::statement('ALTER TABLE enrollment_discounts ADD CONSTRAINT chk_enrollment_discounts_percent CHECK (percent_bp BETWEEN 1 AND 10000)');
    }

    public function down(): void
    {
        $this->dropCheck('enrollment_discounts', 'chk_enrollment_discounts_percent');
        $this->dropCheck('enrollment_discounts', 'chk_enrollment_discounts_amount');
        $this->dropCheck('discounts', 'chk_discounts_percent');
        $this->dropCheck('enrollment_fees', 'chk_enrollment_fees_amount');
        $this->dropCheck('grade_fees', 'chk_grade_fees_amount');
    }

    /**
     * MySQL drops checks with DROP CHECK while MariaDB (used for local
     * verification only; production is MySQL 8.4) needs DROP CONSTRAINT.
     */
    private function dropCheck(string $table, string $name): void
    {
        $keyword = $this->isMariaDb() ? 'CONSTRAINT' : 'CHECK';

        DB::statement("ALTER TABLE {$table} DROP {$keyword} {$name}");
    }

    private function isMariaDb(): bool
    {
        $version = (string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        return str_contains(strtolower($version), 'mariadb');
    }
};
