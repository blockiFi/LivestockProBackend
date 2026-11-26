<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable()->after('last_admin_login_at');
                $table->string('deactivation_reason', 500)->nullable()->after('deactivated_at');
                $table->index('deactivated_at');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('remember_token');
                $table->index('last_login_at');
            }
        });

        Schema::create('admin_login_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_login_challenges');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deactivated_at']);
            $table->dropIndex(['last_login_at']);
            $table->dropColumn(['deactivated_at', 'deactivation_reason', 'last_login_at']);
        });
    }
};
