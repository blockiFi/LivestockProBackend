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
        Schema::table('flock_stages', function (Blueprint $table) {
            // Drop the unique index if it exists
            $table->dropUnique(['name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flock_stages', function (Blueprint $table) {
            // Don't add back the unique constraint - it was removed for a reason
            // (to allow same stage names for different poultry types or contexts)
        });
    }
};
