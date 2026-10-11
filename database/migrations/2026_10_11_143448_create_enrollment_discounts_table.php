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
            $table->unsignedBigInteger('percent_bp');
            $table->unsignedBigInteger('amount_minor');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('enrollment_id', 'ed_enrollment_fk')->references('id')->on('enrollments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_discounts');
    }
};
