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
        Schema::create('feed_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit')->default('kg');

            // Standard nutritional fields (percentages unless noted)
            $table->decimal('crude_protein', 5, 2)->nullable();
            $table->decimal('crude_fat', 5, 2)->nullable();
            $table->decimal('crude_fiber', 5, 2)->nullable();
            $table->decimal('calcium', 5, 2)->nullable();
            $table->decimal('phosphorus', 5, 2)->nullable();
            $table->decimal('metabolizable_energy', 8, 2)->nullable(); // kcal/kg
            $table->decimal('moisture', 5, 2)->nullable();
            $table->decimal('ash', 5, 2)->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['name', 'farm_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_components');
    }
};

