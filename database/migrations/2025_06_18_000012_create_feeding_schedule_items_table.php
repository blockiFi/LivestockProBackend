<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feeding_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feeding_schedule_id')->constrained()->onDelete('cascade');
            $table->foreignId('feed_type_id')->constrained('poultry_feed_types')->onDelete('cascade');
            $table->json('feeding_times')->comment('Array of objects: [{time: "08:00", percentage: 40}, {time: "17:00", percentage: 60}]');
            $table->decimal('quantity', 10, 2);
            $table->integer('feeding_day');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('feeding_schedule_items');
    }
}; 