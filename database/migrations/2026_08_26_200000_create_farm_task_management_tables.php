<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('section', 32); // layers, broilers, ...
            $table->string('priority', 16)->default('medium'); // low|medium|high|critical
            $table->text('instructions')->nullable();
            $table->text('notes')->nullable();
            $table->string('animal_group')->nullable();
            $table->string('medication_name')->nullable();
            $table->text('dosage_instructions')->nullable();
            $table->boolean('require_completion_confirmation')->default(false);
            $table->boolean('require_supervisor_approval')->default(false);
            $table->boolean('require_signature')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['farm_id', 'section']);
        });

        Schema::create('farm_task_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('farm_task_templates')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('section', 32);
            $table->string('priority', 16)->default('medium');
            $table->text('instructions')->nullable();
            $table->text('notes')->nullable();

            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('indefinite')->default(false);
            $table->time('start_time')->nullable(); // null = Continuous
            $table->time('due_time')->nullable();

            $table->string('recurrence', 16)->default('none'); // none|daily|weekly|monthly|custom
            $table->unsignedInteger('repeat_interval')->default(1);
            $table->json('days_of_week')->nullable(); // [1,3,4,6] ISO 1=Mon..7=Sun
            $table->unsignedTinyInteger('month_day')->nullable();

            $table->string('assignment_mode', 16)->default('single'); // single|alternating|all

            $table->string('animal_group')->nullable();
            $table->string('medication_name')->nullable();
            $table->text('dosage_instructions')->nullable();
            $table->boolean('require_completion_confirmation')->default(false);
            $table->boolean('require_supervisor_approval')->default(false);
            $table->boolean('require_signature')->default(false);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['farm_id', 'is_active']);
            $table->index(['farm_id', 'section']);
        });

        Schema::create('farm_task_schedule_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('farm_task_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['schedule_id', 'user_id']);
            $table->index(['schedule_id', 'sort_order']);
        });

        Schema::create('farm_task_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('farm_task_schedules')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('section', 32);
            $table->string('priority', 16)->default('medium');
            $table->text('instructions')->nullable();
            $table->text('notes')->nullable();

            $table->date('scheduled_date');
            $table->time('start_time')->nullable();
            $table->time('due_time')->nullable();

            $table->string('status', 24)->default('pending'); // pending|in_progress|completed|overdue|cancelled|skipped
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('animal_group')->nullable();
            $table->string('medication_name')->nullable();
            $table->text('dosage_instructions')->nullable();
            $table->boolean('require_completion_confirmation')->default(false);
            $table->boolean('require_supervisor_approval')->default(false);
            $table->boolean('require_signature')->default(false);
            $table->boolean('awaiting_approval')->default(false);

            $table->timestamp('started_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('occurrence_index')->nullable();
            $table->timestamps();

            $table->index(['farm_id', 'scheduled_date'], 'fti_farm_date_idx');
            $table->index(['farm_id', 'status'], 'fti_farm_status_idx');
            $table->index(['assigned_to_user_id', 'scheduled_date'], 'fti_assignee_date_idx');
            $table->unique(['schedule_id', 'scheduled_date', 'start_time'], 'fti_schedule_date_time_uq');
        });

        Schema::create('farm_task_instance_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('farm_task_instances')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['instance_id', 'user_id']);
        });

        Schema::create('farm_task_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('farm_task_instances')->cascadeOnDelete();
            $table->foreignId('completed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->text('notes')->nullable();
            $table->boolean('worker_confirmed')->default(false);
            $table->string('signature_text')->nullable();
            $table->boolean('supervisor_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->timestamps();

            $table->unique('instance_id');
        });

        Schema::create('farm_task_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instance_id')->nullable()->constrained('farm_task_instances')->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('farm_task_schedules')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['farm_id', 'created_at']);
            $table->index(['instance_id']);
        });

        Schema::create('farm_task_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instance_id')->nullable()->constrained('farm_task_instances')->nullOnDelete();
            $table->string('type', 64);
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['farm_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_task_notifications');
        Schema::dropIfExists('farm_task_audit_logs');
        Schema::dropIfExists('farm_task_completions');
        Schema::dropIfExists('farm_task_instance_assignees');
        Schema::dropIfExists('farm_task_instances');
        Schema::dropIfExists('farm_task_schedule_assignees');
        Schema::dropIfExists('farm_task_schedules');
        Schema::dropIfExists('farm_task_templates');
    }
};
