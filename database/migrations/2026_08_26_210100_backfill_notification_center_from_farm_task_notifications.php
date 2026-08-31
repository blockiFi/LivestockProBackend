<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categories/priorities for the legacy task notification types.
     */
    protected array $legacyMap = [
        'task_assigned' => ['tasks', 'normal'],
        'task_reassigned' => ['tasks', 'normal'],
        'task_updated' => ['tasks', 'low'],
        'task_overdue' => ['tasks', 'high'],
        'task_awaiting_approval' => ['tasks', 'normal'],
        'task_approved' => ['tasks', 'normal'],
        'task_rejected' => ['tasks', 'high'],
        'task_completed' => ['tasks', 'normal'],
        'task_cancelled' => ['tasks', 'normal'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('farm_task_notifications') || !Schema::hasTable('notifications_center')) {
            return;
        }

        DB::table('farm_task_notifications')->orderBy('id')->chunk(200, function ($rows) {
            $now = now();
            $payloadRows = [];

            foreach ($rows as $row) {
                [$category, $priority] = $this->legacyMap[$row->type] ?? ['tasks', 'normal'];

                $payloadRows[] = [
                    'farm_id' => $row->farm_id,
                    'user_id' => $row->user_id,
                    'type' => $row->type,
                    'category' => $category,
                    'priority' => $priority,
                    'title' => $row->title,
                    'body' => $row->body,
                    'action_url' => $row->instance_id
                        ? '/dashboard/poultry/tasks?instance=' . $row->instance_id
                        : null,
                    'action_label' => $row->instance_id ? 'View task' : null,
                    'source_type' => $row->instance_id ? 'App\\Models\\FarmTaskInstance' : null,
                    'source_id' => $row->instance_id,
                    'instance_id' => $row->instance_id,
                    'section' => null,
                    'payload' => $row->payload,
                    'dedupe_key' => 'legacy:farm_task_notification:' . $row->id,
                    'status' => $row->read_at ? 'read' : 'sent',
                    'available_at' => $row->created_at,
                    'read_at' => $row->read_at,
                    'dismissed_at' => null,
                    'created_at' => $row->created_at ?? $now,
                    'updated_at' => $row->updated_at ?? $now,
                ];
            }

            if ($payloadRows !== []) {
                DB::table('notifications_center')->insertOrIgnore($payloadRows);
            }
        });

        // Record the in-app delivery for every migrated row so analytics stay consistent.
        $migrated = DB::table('notifications_center')
            ->where('dedupe_key', 'like', 'legacy:farm_task_notification:%')
            ->select('id', 'read_at', 'created_at')
            ->get();

        foreach ($migrated->chunk(200) as $chunk) {
            $deliveries = [];
            foreach ($chunk as $notification) {
                $deliveries[] = [
                    'notification_id' => $notification->id,
                    'channel' => 'in_app',
                    'status' => $notification->read_at ? 'read' : 'delivered',
                    'attempts' => 1,
                    'max_attempts' => 1,
                    'target' => null,
                    'error' => null,
                    'queued_at' => $notification->created_at,
                    'sent_at' => $notification->created_at,
                    'delivered_at' => $notification->created_at,
                    'failed_at' => null,
                    'next_attempt_at' => null,
                    'created_at' => $notification->created_at,
                    'updated_at' => $notification->created_at,
                ];
            }

            DB::table('notification_deliveries')->insertOrIgnore($deliveries);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('notifications_center')) {
            return;
        }

        DB::table('notifications_center')
            ->where('dedupe_key', 'like', 'legacy:farm_task_notification:%')
            ->delete();
    }
};
