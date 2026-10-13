<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('enrollment_fee_id')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('kind', 16);
            $table->string('method', 16);
            $table->string('transaction_number', 100)->nullable();
            $table->date('paid_on');
            $table->string('note', 255)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->foreign('enrollment_id', 'pay_enrollment_fk')->references('id')->on('enrollments')->restrictOnDelete();
            $table->foreign('enrollment_fee_id', 'pay_enrollment_fee_fk')->references('id')->on('enrollment_fees')->restrictOnDelete();
            $table->index('enrollment_id', 'pay_enrollment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
