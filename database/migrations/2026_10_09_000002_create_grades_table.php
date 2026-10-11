<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('number');
            $table->timestamps();

            $table->unique(['school_id', 'number']);
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
