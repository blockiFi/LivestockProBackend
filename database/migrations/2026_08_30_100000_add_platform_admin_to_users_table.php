<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('profile_photo');
            $table->string('platform_admin_role')->nullable()->after('is_platform_admin');
            $table->timestamp('last_admin_login_at')->nullable()->after('platform_admin_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_platform_admin', 'platform_admin_role', 'last_admin_login_at']);
        });
    }
};
