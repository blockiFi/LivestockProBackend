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
        Schema::create('poultry_feed_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('poultry_feed_inventory_id')->nullable()->constrained('poultry_feed_inventories')->onDelete('cascade');
            $table->foreignId('poultry_feed_type_id')->constrained('poultry_feed_types')->onDelete('cascade');
            $table->foreignId('flock_id')->constrained()->onDelete('cascade');
            $table->decimal('quantity' , 10, 2)->default(0);
            $table->decimal('unit_cost', 10, 2)->default(0); // cost to farm
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->date('usage_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_feed_usages');
    }
};
