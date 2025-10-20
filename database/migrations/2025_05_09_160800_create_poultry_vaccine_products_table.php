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
        Schema::create('poultry_vaccine_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade')->nullable();
            $table->enum('type', ['default', 'user']);
            $table->foreignId('poultry_vaccine_id')->constrained()->onDelete('cascade');
            $table->string('name')->unique();
            $table->string('image_url')->nullable();
            $table->string('manufacturer')->nullable();
            $table->foreignId('administration_method_id')->constrained()->onDelete('cascade');
            $table->integer('withdrawal_period')->nullable();
            $table->string('withdrawal_period_unit')->default('days');
            $table->integer('dosage')->nullable();
            $table->string('dosage_unit')->default('mL'); 
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_vaccine_products');
    }
};
