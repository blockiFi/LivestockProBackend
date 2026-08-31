<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_import_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('schedule_import_id');

            $table->string('kind'); // vaccination|medication|feeding

            $table->unsignedInteger('age_days')->nullable();     // vaccination/medication
            $table->unsignedInteger('feeding_day')->nullable();  // feeding

            $table->string('name')->nullable();
            $table->unsignedInteger('dose')->nullable();
            $table->unsignedInteger('withdrawal_period_days')->nullable();
            $table->text('storage_instructions')->nullable();
            $table->text('description')->nullable();

            // Feeding-specific
            $table->unsignedBigInteger('feed_type_id')->nullable(); // poultry_feed_types.id
            $table->decimal('quantity', 10, 2)->nullable();
            $table->json('feeding_times')->nullable(); // [{time:"08:00",percentage:50},...]

            $table->decimal('confidence', 5, 2)->nullable(); // 0..1 or 0..100, up to UI
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('schedule_import_id')->references('id')->on('schedule_imports')->onDelete('cascade');
            $table->foreign('feed_type_id')->references('id')->on('poultry_feed_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_import_items');
    }
};

