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
        Schema::create('poultry_feed_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->onDelete('cascade');
            $table->enum('type', ['default', 'user']);  
            $table->foreignId('poultry_type_id')->constrained()->onDelete('cascade');
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->integer('start_age')->nullable();
            $table->integer('end_age')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_feed_types');
    }
};
