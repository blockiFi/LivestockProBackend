<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feeding_batch_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feeding_batch_schedule_id')->constrained()->onDelete('cascade'); // Reference to feeding_batch_schedules table
            $table->foreignId('feeding_schedule_item_id')->constrained()->onDelete('cascade'); // Reference to feeding_schedule_items table
            $table->json('actual_feeding_time')->nullable()->comment('Array of objects: [{time: "08:00", percentage: 40}, {time: "17:00", percentage: 60}]'); // Actual feeding times and percentages
            $table->decimal('actual_quantity', 10, 2)->nullable(); // Actual quantity fed
            $table->date("feeding_date"); // Date when feeding occurred
            $table->enum('status', ['scheduled', 'completed', 'missed', 'late'])->default('scheduled')->comment('Status of the feeding batch schedule item'); // Status of the item
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('feeding_batch_schedule_items');
    }
}; 