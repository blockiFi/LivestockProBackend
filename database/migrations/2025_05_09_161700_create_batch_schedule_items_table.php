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
        Schema::create('batch_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_schedule_id')->constrained()->onDelete('cascade');
            $table->foreignId('schedule_item_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['scheduled', 'completed', 'missed' , 'late'])->default('scheduled')->comment('Status of the vaccination schedule');
            $table->date('scheduled_date')->comment('Date when the vaccination is scheduled');
            $table->date('actual_date')->nullable()->comment('Date when the vaccination was actually administered');
            $table->string('administered_by')->nullable()->comment('Person who administered the vaccination');
            $table->foreignId('poultry_vaccine_product_id')->nullable()->constrained()->onDelete('cascade')->comment('Vaccine product used for the vaccination');
            $table->foreignId('vaccine_product_batch_id')->nullable();
            $table->foreignId('poultry_medication_id')->constrained()->onDelete('cascade')->comment('Vaccine product used for the vaccination');
            $table->integer('dosage')->nullable()->comment('Dosage of the vaccine administered');
            $table->decimal('quantity', 10, 2)->nullable()->comment('Quantity of vaccine used');
            $table->decimal('cost', 10, 2)->nullable()->comment('Cost of the vaccination');
            $table->text('notes')->nullable()->comment('Additional notes regarding the vaccination');
            $table->foreignId('administration_method_id')->constrained()->onDelete('cascade')->comment('Method of administration (e.g., injection, oral, etc.)')->nullable();
            $table->timestamps();
            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batch_schedule_items');
    }
};
