<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flock_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 20)->default('active'); // active|archived
            $table->timestamp('last_message_at')->nullable();
            $table->text('memory_summary')->nullable();
            $table->timestamps();

            $table->index(['farm_id', 'flock_id', 'user_id', 'status']);
            $table->index(['user_id', 'flock_id', 'last_message_at']);
        });

        Schema::create('flock_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('flock_chat_sessions')->cascadeOnDelete();
            $table->string('role', 20); // user|assistant|tool|system
            $table->longText('content')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->string('tool_name')->nullable();
            $table->json('pending_actions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'id']);
        });

        Schema::create('flock_chat_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40)->default('fact'); // preference|fact|action_outcome
            $table->text('content');
            $table->foreignId('source_session_id')->nullable()->constrained('flock_chat_sessions')->nullOnDelete();
            $table->timestamps();

            $table->index(['farm_id', 'flock_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flock_chat_memories');
        Schema::dropIfExists('flock_chat_messages');
        Schema::dropIfExists('flock_chat_sessions');
    }
};
