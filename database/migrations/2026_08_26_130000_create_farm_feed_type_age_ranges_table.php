<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_feed_type_age_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('poultry_feed_type_id')->constrained('poultry_feed_types')->onDelete('cascade');
            $table->unsignedInteger('start_age');
            // null => open-ended (age >= start_age)
            $table->unsignedInteger('end_age')->nullable();
            $table->timestamps();

            $table->unique(['farm_id', 'poultry_feed_type_id'], 'farm_feed_type_age_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_feed_type_age_ranges');
    }
};
