<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Stored answers were hashed against the old questions and will never
     * match the new ones, so clear them and require users to set them again.
     */
    public function up(): void
    {
        DB::table('users')->update([
            'security_answer_1' => null,
            'security_answer_2' => null,
            'security_answer_3' => null,
        ]);
    }

    /**
     * Hashed answers cannot be restored once cleared.
     */
    public function down(): void
    {
        //
    }
};
