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
        Schema::create('schedule_item_administration_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('administration_method_id')->constrained('administration_methods', 'id', 'fk_admin_method')->onDelete('cascade');
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['schedule_item_id', 'administration_method_id'], 'unique_schedule_admin_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_item_administration_methods');
    }
};
