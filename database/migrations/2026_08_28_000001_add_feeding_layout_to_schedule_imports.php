<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_imports', function (Blueprint $table) {
            $table->string('feeding_layout', 16)->nullable()->after('status');
            $table->text('feeding_layout_reason')->nullable()->after('feeding_layout');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_imports', function (Blueprint $table) {
            $table->dropColumn(['feeding_layout', 'feeding_layout_reason']);
        });
    }
};
