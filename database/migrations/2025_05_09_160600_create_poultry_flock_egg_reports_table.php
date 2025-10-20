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
        Schema::create('poultry_flock_egg_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flock_id')->constrained()->cascadeOnDelete();
            $table->integer('eggs_collected')->default(0);
            $table->decimal('average_egg_weight' , 10 ,2)->nullable()->default(0);
            $table->decimal('production_percentage' ,10 ,2)->default(0);
            $table->integer('bird_count')->default(0);
            $table->ForeignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->date('date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_flock_egg_reports');
    }
};
