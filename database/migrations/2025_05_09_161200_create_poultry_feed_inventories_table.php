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
        Schema::create('poultry_feed_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('poultry_feed_type_id')->constrained()->onDelete('cascade');
            $table->decimal('quantity' ,10, 2)->default(0);
            $table->string('batch_number')->nullable();
            $table->string('manufacturer')->nullable();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('last_restocked')->nullable();
            $table->decimal('unit_cost', 10, 2); // cost to farm
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_feed_inventories');
    }
};
