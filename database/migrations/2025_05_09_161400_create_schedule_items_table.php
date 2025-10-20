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
        Schema::create('schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->onDelete('cascade');
            $table->integer('age_days')->comment('Age in days when the vaccination should be administered');
            $table->foreignId('poultry_vaccine_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('poultry_medication_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('name');
            $table->enum('dose_unit', ['ml', 'mg', 'tablet', 'g', 'l', 'unit', 'other'])->nullable()->comment('Unit for the dosage (e.g., ml, mg, tablet)');
            $table->integer('dose')->comment('Number of doses to be administered');
            $table->integer('withdrawal_period_days')->nullable()->comment('Days before slaughter/harvest');
            $table->text('storage_instructions')->nullable();  
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_items');
    }
};
