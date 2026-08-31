<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flock_house_allocations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('flock_id')->constrained('flocks')->onDelete('cascade');
            $table->foreignId('house_id')->constrained('poultry_houses')->onDelete('cascade');
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['flock_id', 'house_id']);
            $table->index(['farm_id', 'flock_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flock_house_allocations');
    }
};

