<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flock_daily_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('flock_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->integer('mortality')->default(0);
            $table->integer('culls')->default(0);
            $table->decimal('feed_consumed_kg',10,2)->default(0);
            $table->decimal('water_consumed_liters',10,2)->nullable();
            $table->decimal('avg_weight_grams',10,2)->nullable();
            $table->decimal('min_temperature',5,2)->nullable();
            $table->decimal('max_temperature',5,2)->nullable();
            $table->decimal('humidity',5,2)->nullable();
            $table->decimal('light_hours',4,2)->nullable();
            $table->integer('eggs_collected')->nullable();
            $table->integer('eggs_broken')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flock_daily_records');
    }
};
