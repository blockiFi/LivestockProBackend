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
        Schema::create('poultry_feed_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('farm_id')
                ->nullable()
                ->constrained('farms')
                ->onDelete('cascade');
            // add relation to poultry feed type
            $table->foreignId('poultry_feed_type_id')
                ->nullable()
                ->constrained('poultry_feed_types')
                ->onDelete('set null');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            $table->enum('status', ['active', 'inactive'])->default('active');
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_feed_products');
    }
};
