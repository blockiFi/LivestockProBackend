<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_settings')) {
            return;
        }

        DB::table('user_settings')
            ->whereIn('theme', ['dark', 'system'])
            ->update(['theme' => 'light']);
    }

    public function down(): void
    {
        // No-op: dark mode remains disabled.
    }
};
