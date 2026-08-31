<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->string('theme')->default('system');
            $table->string('locale', 10)->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('date_format', 20)->default('Y-m-d');
            $table->boolean('notify_schedules')->default(true);
            $table->boolean('notify_low_stock')->default(true);
            $table->boolean('notify_mortality')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
