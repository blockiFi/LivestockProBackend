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
        Schema::create('poultry_vaccine_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poultry_vaccine_product_id')->constrained()->onDelete('cascade');
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->decimal('quantity', 10, 2)->default(0);
            $table->enum('status', ['available', 'in_use', 'depleted'])->default('available');
            $table->string('manufacturer')->nullable();
            $table->text('notes')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('manufacture_date')->nullable();
            $table->date('last_restocked')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_cost', 10, 2);// cost to farm
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_vaccine_inventories');
    }
};
