<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->collation('utf8mb4_unicode_ci')->unique('fee_types_name_unique');
            $table->string('system_key')->nullable()->unique('fee_types_system_key_unique');
            $table->boolean('is_mandatory')->default(false);
            $table->timestamps();
        });

        DB::table('fee_types')->insert([
            'name' => 'Tuition',
            'system_key' => 'tuition',
            'is_mandatory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('fee_types')->where('system_key', 'tuition')->delete();

        Schema::dropIfExists('fee_types');
    }
};
