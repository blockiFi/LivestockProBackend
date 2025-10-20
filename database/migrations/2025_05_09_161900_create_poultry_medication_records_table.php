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
        Schema::create('poultry_medication_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('flock_id')->constrained()->onDelete('cascade');
            $table->foreignId('poultry_medication_id')->constrained()->onDelete('cascade');
            $table->foreignId('poultry_medication_inventory_id')->nullable()->constrained('poultry_medication_inventories', 'id', 'fk_med_inventory')->onDelete('cascade');
            $table->date('date')->comment('Date Medication was administered');
            $table->string('administered_by')->nullable()->comment('Person who administered the medication');
            $table->integer('dosage')->nullable()->comment('Dosage of the medication administered');
            $table->string('dosage_unit')->default('mL')->comment('Unit of dosage (e.g., mL, L, etc.)');
            $table->decimal('quantity', 10, 2)->nullable()->comment('Quantity of medication used');
            $table->decimal('cost', 10, 2)->nullable()->comment('Cost of the medication');
            $table->text('notes')->nullable()->comment('Additional notes regarding the medication');
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
        Schema::dropIfExists('poultry_medication_records');
    }
};
