<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flock_transfer_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('transfer_id')->constrained('flock_transfers')->onDelete('cascade');
            $table->foreignId('from_house_id')->nullable()->constrained('poultry_houses')->nullOnDelete();
            $table->foreignId('to_house_id')->nullable()->constrained('poultry_houses')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index(['transfer_id']);
            $table->index(['from_house_id']);
            $table->index(['to_house_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flock_transfer_lines');
    }
};

