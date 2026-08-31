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
        Schema::create('feed_compositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poultry_feed_product_id')
                ->constrained('poultry_feed_products')
                ->onDelete('cascade');
            $table->foreignId('feed_component_id')
                ->constrained('feed_components')
                ->onDelete('cascade');
            $table->decimal('percentage', 5, 2); // 0 - 100
            $table->timestamps();

            $table->unique(['poultry_feed_product_id', 'feed_component_id'], 'feed_product_component_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_compositions');
    }
};

