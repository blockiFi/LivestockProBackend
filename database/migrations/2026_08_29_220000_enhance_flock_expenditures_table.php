<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flock_expenditures', function (Blueprint $table) {
            $table->string('payment_method', 50)->nullable()->after('description');
            $table->string('reference_no', 100)->nullable()->after('payment_method');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE flock_expenditures MODIFY category VARCHAR(50) NOT NULL');
        } else {
            // Fresh sqlite installs already use string category from the base migration.
        }
    }

    public function down(): void
    {
        Schema::table('flock_expenditures', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'reference_no']);
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE flock_expenditures MODIFY category ENUM('feed','medication','vaccination','other') NOT NULL");
        }
    }
};
