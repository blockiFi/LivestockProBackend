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
        Schema::table('poultry_types', function (Blueprint $table) {
            $table->integer('average_lifespan_days')->nullable();
            $table->decimal('average_weight_kg', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poultry_types', function (Blueprint $table) {
            $table->dropColumn([
                'average_lifespan_days',
                'average_weight_kg',
                'is_active'
            ]);
        });
    }
}; 