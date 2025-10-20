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
        Schema::create('flocks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('house_id')->constrained('poultry_houses')->onDelete('cascade');
            $table->foreignId('poultry_weight_report_frequency_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('poultry_type_id')->constrained()->onDelete('cascade');
            $table->foreignId('flock_stage_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('batch_number');
            $table->string('breed');
            $table->string('source');
            $table->integer('quantity');
            $table->date('arrival_date');
            $table->integer('arrival_age_days');
            $table->enum('status',['active','sold','culled','completed']);
            $table->date('expected_end_date')->nullable();
            $table->date('actual_end_date')->nullable();
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
        Schema::dropIfExists('flocks');
    }
};
