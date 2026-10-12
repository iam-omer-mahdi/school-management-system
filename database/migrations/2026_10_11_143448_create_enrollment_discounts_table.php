<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_discounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('enrollment_id')->unique('ed_enrollment_unique');
            $table->unsignedBigInteger('discount_id');
            $table->string('name');
            $table->unsignedBigInteger('percent_bp');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->foreign('enrollment_id', 'ed_enrollment_fk')->references('id')->on('enrollments')->restrictOnDelete();
            $table->foreign('discount_id', 'ed_discount_fk')->references('id')->on('discounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_discounts');
    }
};
