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
        Schema::table('poultry_feed_products', function (Blueprint $table) {
            // Add farm_id if it doesn't exist
            if (!Schema::hasColumn('poultry_feed_products', 'farm_id')) {
                $table->foreignId('farm_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('farms')
                    ->onDelete('cascade');
            }
            
            // Add unit if it doesn't exist
            if (!Schema::hasColumn('poultry_feed_products', 'unit')) {
                $table->string('unit')->nullable()->after('description');
            }
            
            // Add price if it doesn't exist
            if (!Schema::hasColumn('poultry_feed_products', 'price')) {
                $table->decimal('price', 10, 2)->nullable()->after('unit');
            }
            
            // Add created_by if it doesn't exist
            if (!Schema::hasColumn('poultry_feed_products', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('price')
                    ->constrained('users')
                    ->onDelete('set null');
            }
            
            // Add status if it doesn't exist
            if (!Schema::hasColumn('poultry_feed_products', 'status')) {
                $table->enum('status', ['active', 'inactive'])->default('active')->after('created_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_feed_products', function (Blueprint $table) {
            if (Schema::hasColumn('poultry_feed_products', 'farm_id')) {
                $table->dropForeign(['farm_id']);
                $table->dropColumn('farm_id');
            }
            
            if (Schema::hasColumn('poultry_feed_products', 'unit')) {
                $table->dropColumn('unit');
            }
            
            if (Schema::hasColumn('poultry_feed_products', 'price')) {
                $table->dropColumn('price');
            }
            
            if (Schema::hasColumn('poultry_feed_products', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
            
            if (Schema::hasColumn('poultry_feed_products', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
