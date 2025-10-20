<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feeding_batch_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('flock_id')->constrained()->onDelete('cascade');
            $table->foreignId('feeding_schedule_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled'])->default('scheduled')->comment('Status of the feeding batch schedule');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('feeding_batch_schedules');
    }
}; 