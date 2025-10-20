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
        Schema::table('liter_types', function (Blueprint $table) {
            $table->json('advantages')->nullable()->after('description');
            $table->json('disadvantages')->nullable()->after('advantages');
            $table->boolean('is_active')->default(true)->after('disadvantages');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liter_types', function (Blueprint $table) {
            $table->dropColumn(['advantages', 'disadvantages', 'is_active']);
        });
    }
};
