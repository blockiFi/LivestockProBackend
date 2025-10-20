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
            $table->integer('from_age')->nullable()->after('description')->comment('Age in days when this stage begins');
            $table->integer('to_age')->nullable()->after('from_age')->comment('Age in days when this stage ends');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flock_stages', function (Blueprint $table) {
            $table->dropColumn(['from_age', 'to_age']);
        });
    }
};
