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
        Schema::table('poultry_feed_inventories', function (Blueprint $table) {
            // Add nullable foreign key to reference standardized feed products
            $table->foreignId('poultry_feed_product_id')
                ->nullable()
                ->constrained('poultry_feed_products')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_feed_inventories', function (Blueprint $table) {
            // Drop the foreign key and column
            if (Schema::hasColumn('poultry_feed_inventories', 'poultry_feed_product_id')) {
                $table->dropConstrainedForeignId('poultry_feed_product_id');
            }
        });
    }
};
