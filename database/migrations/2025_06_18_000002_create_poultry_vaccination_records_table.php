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
        Schema::create('poultry_vaccination_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('flock_id')->constrained()->onDelete('cascade');
            $table->foreignId('poultry_vaccine_id')->constrained()->onDelete('cascade');
            $table->foreignId('poultry_vaccine_inventory_id')->nullable()->constrained('poultry_vaccine_inventories')->onDelete('cascade');
            $table->date('date')->comment('Date Vaccine was administered');
            $table->string('administered_by')->nullable()->comment('Person who administered the vaccine');
            $table->integer('dosage')->nullable()->comment('Dosage of the vaccine administered');
            $table->string('dosage_unit')->default('mL')->comment('Unit of dosage (e.g., mL, L, etc.)');
            $table->decimal('quantity', 10, 2)->nullable()->comment('Quantity of vaccine used');
            $table->decimal('cost', 10, 2)->nullable()->comment('Cost of the vaccine');
            $table->text('notes')->nullable()->comment('Additional notes regarding the vaccination');
            $table->foreignId('administration_method_id')->constrained()->onDelete('cascade')->comment('Method of administration (e.g., injection, oral, etc.)');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_vaccination_records');
    }
}; 