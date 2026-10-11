<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_fees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('grade_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('fee_type_id');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->foreign('grade_id', 'gf_grade_fk')->references('id')->on('grades')->restrictOnDelete();
            $table->foreign('academic_year_id', 'gf_year_fk')->references('id')->on('academic_years')->restrictOnDelete();
            $table->foreign('fee_type_id', 'gf_type_fk')->references('id')->on('fee_types')->restrictOnDelete();
            $table->unique(['grade_id', 'academic_year_id', 'fee_type_id'], 'gf_grade_year_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_fees');
    }
};
