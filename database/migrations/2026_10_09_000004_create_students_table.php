<?php

declare(strict_types=1);

use App\Enums\StudentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('student_number')->unique();
            $table->string('name');
            $table->string('phone');
            $table->string('secondary_phone')->nullable();
            $table->string('home_address')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('blood_type', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_relationship')->nullable();
            $table->string('status')->default(StudentStatus::Active->value);
            $table->string('status_note')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('phone');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
