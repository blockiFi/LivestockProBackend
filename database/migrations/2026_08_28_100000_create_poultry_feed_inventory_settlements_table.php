<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poultry_feed_inventory_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_inventory_id')->constrained('poultry_feed_inventories')->cascadeOnDelete();
            $table->foreignId('to_inventory_id')->constrained('poultry_feed_inventories')->cascadeOnDelete();
            $table->foreignId('usage_id')->nullable()->constrained('poultry_feed_usages')->nullOnDelete();
            $table->foreignId('source_usage_id')->nullable()->constrained('poultry_feed_usages')->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();

            $table->index(['to_inventory_id']);
            $table->index(['from_inventory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_feed_inventory_settlements');
    }
};
