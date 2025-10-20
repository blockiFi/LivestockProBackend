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
        Schema::create('poultry_mortality_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('flock_id')->constrained()->onDelete('cascade');
            $table->foreignId('poultry_type_id')->constrained()->onDelete('cascade');
            $table->integer('mortality_count')->default(0);
            $table->integer('average_weight')->nullable()->default(0);
            $table->integer('mortality_percentage')->default(0);
            $table->integer('bird_count')->default(0);
            $table->date('date')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_mortality_reports');
    }
};
