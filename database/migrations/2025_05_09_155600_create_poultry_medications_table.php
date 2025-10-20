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
        Schema::create('poultry_medications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('farm_id')->constrained()->onDelete('cascade')->nullable();
            $table->enum('type', ['default', 'user']);            
            $table->string('name');
            $table->string('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_medications');
    }
};
