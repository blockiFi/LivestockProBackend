<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_center', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type', 64);
            $table->string('category', 32);
            $table->string('priority', 16)->default('normal');

            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();
            $table->string('action_label', 64)->nullable();

            // Source record that generated the notification (task instance, flock, inventory item...)
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('instance_id')->nullable()->constrained('farm_task_instances')->nullOnDelete();
            $table->string('section', 32)->nullable();

            $table->json('payload')->nullable();

            // Identity used to guarantee one notification per logical event + recipient.
            $table->string('dedupe_key', 191)->nullable();

            $table->string('status', 16)->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique('dedupe_key', 'notif_dedupe_unique');
            $table->index(['user_id', 'read_at'], 'notif_user_read_idx');
            $table->index(['user_id', 'category', 'created_at'], 'notif_user_cat_idx');
            $table->index(['farm_id', 'created_at'], 'notif_farm_created_idx');
            $table->index(['source_type', 'source_id'], 'notif_source_idx');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications_center')->cascadeOnDelete();
            $table->string('channel', 24);
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->string('target')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'channel'], 'notif_delivery_unique');
            $table->index(['status', 'next_attempt_at'], 'notif_delivery_retry_idx');
            $table->index(['channel', 'status'], 'notif_delivery_channel_idx');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('farm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'farm_id', 'type'], 'notif_pref_unique');
        });

        Schema::create('user_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('sound_enabled')->default(false);
            $table->boolean('browser_push_enabled')->default(false);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('digest_enabled')->default(false);
            $table->string('quiet_hours_start', 8)->nullable();
            $table->string('quiet_hours_end', 8)->nullable();
            $table->timestamps();
        });

        Schema::create('farm_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->boolean('enabled')->default(true);
            $table->boolean('mandatory')->default(false);
            $table->boolean('default_in_app')->default(true);
            $table->boolean('default_email')->default(true);
            $table->string('priority', 16)->nullable();
            $table->timestamps();

            $table->unique(['farm_id', 'type'], 'farm_notif_setting_unique');
        });

        Schema::create('farm_notification_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('default_task_reminders')->nullable();
            $table->boolean('escalation_enabled')->default(true);
            $table->unsignedSmallInteger('escalate_to_manager_after_minutes')->default(60);
            $table->unsignedSmallInteger('escalate_high_priority_after_minutes')->default(180);
            $table->boolean('notify_managers_on_completion')->default(true);
            $table->boolean('notify_managers_on_overdue')->default(true);
            $table->unsignedTinyInteger('email_max_attempts')->default(3);
            $table->timestamps();
        });

        // Reminder definitions attached to a schedule (recurring) or a single instance.
        Schema::create('farm_task_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('farm_task_schedules')->cascadeOnDelete();
            $table->foreignId('instance_id')->nullable()->constrained('farm_task_instances')->cascadeOnDelete();
            $table->unsignedSmallInteger('offset_minutes')->default(30);
            $table->string('label', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['schedule_id', 'is_active'], 'task_reminder_schedule_idx');
            $table->index(['instance_id', 'is_active'], 'task_reminder_instance_idx');
        });

        // Materialised reminder occurrences the scheduling engine sweeps.
        Schema::create('scheduled_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reminder_id')->nullable()->constrained('farm_task_reminders')->cascadeOnDelete();
            $table->foreignId('instance_id')->nullable()->constrained('farm_task_instances')->cascadeOnDelete();
            $table->string('type', 64);
            $table->unsignedSmallInteger('offset_minutes')->default(0);
            $table->timestamp('scheduled_for');
            $table->string('status', 16)->default('pending');
            $table->foreignId('notification_id')->nullable()->constrained('notifications_center')->nullOnDelete();
            $table->string('dedupe_key', 191);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('dedupe_key', 'sched_notif_dedupe_unique');
            $table->index(['status', 'scheduled_for'], 'sched_notif_due_idx');
            $table->index(['instance_id'], 'sched_notif_instance_idx');
        });

        Schema::table('farm_task_schedules', function (Blueprint $table) {
            $table->boolean('reminders_enabled')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('farm_task_schedules', function (Blueprint $table) {
            $table->dropColumn('reminders_enabled');
        });

        Schema::dropIfExists('scheduled_notifications');
        Schema::dropIfExists('farm_task_reminders');
        Schema::dropIfExists('farm_notification_configs');
        Schema::dropIfExists('farm_notification_settings');
        Schema::dropIfExists('user_notification_settings');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications_center');
    }
};
