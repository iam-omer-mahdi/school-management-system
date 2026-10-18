<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `code` was the school type *and* the display label. The label is now the
     * name the administrator types, and what is left of `code` is the type
     * (plan D8), so the column is renamed and the values carry over unchanged.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('type')->nullable()->after('id');
        });

        DB::table('schools')->update(['type' => DB::raw('code')]);

        Schema::table('schools', function (Blueprint $table): void {
            $table->string('type')->nullable(false)->change();
        });

        Schema::table('schools', function (Blueprint $table): void {
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('code')->nullable()->after('id');
        });

        DB::table('schools')->update(['code' => DB::raw('type')]);

        Schema::table('schools', function (Blueprint $table): void {
            $table->string('code')->nullable(false)->change();
        });

        Schema::table('schools', function (Blueprint $table): void {
            $table->unique('code');
            $table->dropColumn('type');
        });
    }
};
