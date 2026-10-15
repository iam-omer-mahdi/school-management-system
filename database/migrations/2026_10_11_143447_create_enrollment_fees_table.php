<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_fees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('grade_fee_id');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->foreign('enrollment_id', 'ef_enrollment_fk')->references('id')->on('enrollments')->restrictOnDelete();
            $table->foreign('grade_fee_id', 'ef_grade_fee_fk')->references('id')->on('grade_fees')->restrictOnDelete();
            $table->unique(['enrollment_id', 'grade_fee_id'], 'ef_enrollment_grade_fee_unique');
            $table->index('enrollment_id', 'ef_enrollment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_fees');
    }
};
