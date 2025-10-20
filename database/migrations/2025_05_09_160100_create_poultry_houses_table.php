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
        Schema::create('poultry_houses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->foreignId('poultry_type_id')->constrained()->onDelete('cascade');
            $table->enum('liter_type_id',['deepLiter','bateryCage']);
            $table->integer('capacity');
            $table->string('dimensions')->nullable();
            $table->date('construction_date')->nullable();
            $table->date('last_maintenance_date')->nullable();
            $table->enum('status',['active','inactive','maintenance','empty']);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poultry_houses');
    }
};
