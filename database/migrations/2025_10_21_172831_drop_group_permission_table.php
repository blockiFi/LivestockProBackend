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
        Schema::dropIfExists('group_permission');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('group_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->onDelete('cascade');
            $table->foreignId('permission_id')->constrained()->onDelete('cascade');
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->unique(['group_id', 'permission_id']);
        });
    }
};
