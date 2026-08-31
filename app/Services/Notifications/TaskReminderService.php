<?php

namespace App\Services\Notifications;

use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Models\FarmTaskReminder;
use App\Models\FarmTaskSchedule;
use App\Models\ScheduledNotification;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Task reminder engine.
 *
 * Reminders are configured once (on a schedule, or ad hoc on a single
 * instance) and then materialised into `scheduled_notifications` rows for every
 * generated occurrence. Materialising is idempotent: regenerating a recurring
 * schedule never produces duplicate reminders because each row carries a
 * dedupe key built from instance + offset + recipient + fire time.
 */
class TaskReminderService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Replace the reminder set for a schedule.
     *
     * @param  list<array{offset_minutes: int, label?: string|null}>|list<int>  $reminders
     */
    public function syncScheduleReminders(FarmTaskSchedule $schedule, array $reminders): void
    {
        $normalized = $this->normalizeReminders($reminders);

        $schedule->reminders()->delete();

        foreach ($normalized as $reminder) {
            FarmTaskReminder::create([
                'farm_id' => $schedule->farm_id,
                'schedule_id' => $schedule->id,
                'offset_minutes' => $reminder['offset_minutes'],
                'label' => $reminder['label'],
                'is_active' => true,
            ]);
        }

        // Existing occurrences must pick up the new configuration.
        $this->clearPendingForSchedule($schedule);
        $this->materializeForSchedule($schedule);
    }

    /**
     * @param  list<array{offset_minutes: int, label?: string|null}>|list<int>  $reminders
     */
    public function syncInstanceReminders(FarmTaskInstance $instance, array $reminders): void
    {
        $normalized = $this->normalizeReminders($reminders);

        $instance->reminders()->delete();

        foreach ($normalized as $reminder) {
            FarmTaskReminder::create([
                'farm_id' => $instance->farm_id,
                'instance_id' => $instance->id,
                'offset_minutes' => $reminder['offset_minutes'],
                'label' => $reminder['label'],
                'is_active' => true,
            ]);
        }

        $this->cancelPendingForInstance($instance);
        $this->materializeForInstance($instance);
    }

    /**
     * Applies the farm's configured default reminders to a new schedule.
     */
    public function applyFarmDefaults(FarmTaskSchedule $schedule): void
    {
        $farm = $schedule->farm ?: Farm::find($schedule->farm_id);
        $defaults = $farm?->notificationConfigOrDefault()->default_task_reminders;

        if (empty($defaults)) {
            return;
        }

        $this->syncScheduleReminders($schedule, $defaults);
    }

    /**
     * Reminder rows that apply to an instance. Instance-level overrides replace
     * the schedule-level set entirely so a one-off task can opt out.
     *
     * @return Collection<int, FarmTaskReminder>
     */
    public function remindersFor(FarmTaskInstance $instance): Collection
    {
        $own = $instance->reminders()->where('is_active', true)->get();

        if ($own->isNotEmpty()) {
            return $own;
        }

        if (!$instance->schedule_id) {
            return collect();
        }

        $schedule = $instance->schedule ?: FarmTaskSchedule::find($instance->schedule_id);

        if (!$schedule || !$schedule->reminders_enabled) {
            return collect();
        }

        return $schedule->reminders()->where('is_active', true)->get();
    }

    public function materializeForSchedule(FarmTaskSchedule $schedule, ?int $horizonDays = null): int
    {
        $horizon = CarbonImmutable::now()->addDays($horizonDays ?? (int) config('notifications.reminders.horizon_days'));

        $count = 0;

        $schedule->instances()
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereDate('scheduled_date', '>=', CarbonImmutable::now()->subDay()->toDateString())
            ->whereDate('scheduled_date', '<=', $horizon->toDateString())
            ->orderBy('scheduled_date')
            ->chunkById(200, function ($instances) use (&$count) {
                foreach ($instances as $instance) {
                    $count += $this->materializeForInstance($instance);
                }
            });

        return $count;
    }

    public function materializeForFarm(int $farmId, ?int $horizonDays = null): int
    {
        $horizon = CarbonImmutable::now()->addDays($horizonDays ?? (int) config('notifications.reminders.horizon_days'));
        $count = 0;

        FarmTaskInstance::query()
            ->where('farm_id', $farmId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereDate('scheduled_date', '>=', CarbonImmutable::now()->subDay()->toDateString())
            ->whereDate('scheduled_date', '<=', $horizon->toDateString())
            ->orderBy('scheduled_date')
            ->chunkById(200, function ($instances) use (&$count) {
                foreach ($instances as $instance) {
                    $count += $this->materializeForInstance($instance);
                }
            });

        return $count;
    }

    /**
     * Creates the pending reminder occurrences for one task instance.
     */
    public function materializeForInstance(FarmTaskInstance $instance): int
    {
        if (!$instance->isOpen()) {
            return 0;
        }

        $reminders = $this->remindersFor($instance);

        if ($reminders->isEmpty()) {
            return 0;
        }

        $recipients = $this->recipientIdsFor($instance);

        if ($recipients === []) {
            return 0;
        }

        $timezone = $this->timezoneFor($instance);
        $startAt = $instance->scheduledStartAt($timezone);
        $created = 0;

        foreach ($reminders as $reminder) {
            $fireAt = $startAt->subMinutes((int) $reminder->offset_minutes);

            foreach ($recipients as $userId) {
                $created += $this->createOccurrence($instance, $reminder, $userId, $fireAt, $timezone) ? 1 : 0;
            }
        }

        return $created;
    }

    protected function createOccurrence(
        FarmTaskInstance $instance,
        FarmTaskReminder $reminder,
        int $userId,
        CarbonImmutable $fireAt,
        string $timezone,
    ): bool {
        $fireAtUtc = $fireAt->utc();
        $type = $this->reminderType($instance, (int) $reminder->offset_minutes);

        // Identity: instance + reminder type + fire time + recipient.
        $dedupeKey = sprintf(
            'reminder:i%d:o%d:t%s:u%d',
            $instance->id,
            $reminder->offset_minutes,
            $fireAtUtc->format('YmdHi'),
            $userId
        );

        try {
            $occurrence = ScheduledNotification::firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'farm_id' => $instance->farm_id,
                    'user_id' => $userId,
                    'reminder_id' => $reminder->id,
                    'instance_id' => $instance->id,
                    'type' => $type,
                    'offset_minutes' => (int) $reminder->offset_minutes,
                    'scheduled_for' => $fireAtUtc,
                    'status' => ScheduledNotification::STATUS_PENDING,
                    'payload' => [
                        'reminder_label' => $reminder->resolved_label,
                        'timezone' => $timezone,
                    ],
                ]
            );
        } catch (QueryException $e) {
            Log::warning('Could not materialise reminder occurrence', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $occurrence->wasRecentlyCreated;
    }

    /**
     * Fires every reminder whose time has come. Safe to run repeatedly.
     */
    public function processDue(?CarbonImmutable $now = null, ?int $farmId = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $staleAfter = (int) config('notifications.reminders.stale_after_minutes', 120);

        $sent = 0;
        $skipped = 0;

        ScheduledNotification::query()
            ->with(['instance.assignee', 'reminder'])
            ->due($now)
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->orderBy('scheduled_for')
            ->chunkById(100, function ($occurrences) use (&$sent, &$skipped, $now, $staleAfter) {
                foreach ($occurrences as $occurrence) {
                    $result = $this->fire($occurrence, $now, $staleAfter);
                    $result ? $sent++ : $skipped++;
                }
            });

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    protected function fire(ScheduledNotification $occurrence, CarbonImmutable $now, int $staleAfter): bool
    {
        $instance = $occurrence->instance;

        // The task is gone or already handled: nothing useful left to say.
        if (!$instance || !$instance->isOpen()) {
            $occurrence->forceFill([
                'status' => ScheduledNotification::STATUS_CANCELLED,
                'processed_at' => $now,
            ])->save();

            return false;
        }

        if ($occurrence->scheduled_for->lt($now->subMinutes($staleAfter))) {
            $occurrence->forceFill([
                'status' => ScheduledNotification::STATUS_SKIPPED,
                'processed_at' => $now,
            ])->save();

            return false;
        }

        $timezone = $occurrence->payload['timezone'] ?? $this->timezoneFor($instance);
        $reminderLabel = $occurrence->payload['reminder_label'] ?? null;
        $dueAt = $instance->scheduledStartAt($timezone);

        $message = NotificationMessage::make($occurrence->type)
            ->farm($occurrence->farm_id)
            ->to($occurrence->user_id)
            ->taskInstance($instance)
            ->section($instance->section)
            ->priority($this->reminderPriority($instance))
            ->title($this->reminderTitle($instance, $occurrence))
            ->body($this->reminderBody($instance, $occurrence, $dueAt, $timezone))
            ->action('/dashboard/poultry/tasks?instance=' . $instance->id, 'View task')
            ->dedupe('reminder_fired:' . $occurrence->dedupe_key)
            ->with([
                'reminder_label' => $reminderLabel,
                'due_time' => $dueAt->format('g:i A'),
            ])
            ->payload([
                'instance_id' => $instance->id,
                'reminder_id' => $occurrence->reminder_id,
                'offset_minutes' => $occurrence->offset_minutes,
            ]);

        $notification = $this->notifications->send($message)->first();

        $occurrence->forceFill([
            'status' => ScheduledNotification::STATUS_SENT,
            'notification_id' => $notification?->id,
            'processed_at' => $now,
        ])->save();

        return true;
    }

    protected function reminderType(FarmTaskInstance $instance, int $offsetMinutes): string
    {
        if ($instance->section === 'medication') {
            return NotificationType::MEDICATION_REMINDER;
        }

        if ($instance->section === 'feeding') {
            return NotificationType::FEEDING_REMINDER;
        }

        return $offsetMinutes > 0
            ? NotificationType::TASK_DUE_SOON
            : NotificationType::TASK_DUE_TODAY;
    }

    protected function reminderPriority(FarmTaskInstance $instance): string
    {
        return in_array($instance->priority, ['critical', 'high'], true)
            ? $instance->priority
            : 'normal';
    }

    protected function reminderTitle(FarmTaskInstance $instance, ScheduledNotification $occurrence): string
    {
        if ($occurrence->offset_minutes <= 0) {
            return $instance->title . ' is due now';
        }

        return $instance->title . ' is due in ' . $this->humanizeMinutes((int) $occurrence->offset_minutes);
    }

    protected function reminderBody(
        FarmTaskInstance $instance,
        ScheduledNotification $occurrence,
        CarbonImmutable $dueAt,
        string $timezone,
    ): string {
        $when = $dueAt->setTimezone($timezone)->format('D, d M') . ' at ' . $dueAt->setTimezone($timezone)->format('g:i A');
        $section = ucfirst(str_replace('_', ' ', (string) $instance->section));

        return trim(sprintf('%s · %s', $section, $when));
    }

    protected function humanizeMinutes(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days === 1 ? '1 day' : $days . ' days';
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 hour' : $hours . ' hours';
        }

        return $minutes . ' minutes';
    }

    /**
     * @return list<int>
     */
    protected function recipientIdsFor(FarmTaskInstance $instance): array
    {
        $ids = [];

        if ($instance->assigned_to_user_id) {
            $ids[] = (int) $instance->assigned_to_user_id;
        }

        // "All assignees" tasks remind everyone on the instance.
        foreach ($instance->assignees()->pluck('users.id') as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    protected function timezoneFor(FarmTaskInstance $instance): string
    {
        $farm = $instance->farm ?: Farm::with('settings')->find($instance->farm_id);

        return $farm?->resolveTimezone() ?? config('app.timezone', 'UTC');
    }

    public function cancelPendingForInstance(FarmTaskInstance $instance): int
    {
        return ScheduledNotification::query()
            ->where('instance_id', $instance->id)
            ->where('status', ScheduledNotification::STATUS_PENDING)
            ->update([
                'status' => ScheduledNotification::STATUS_CANCELLED,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    protected function clearPendingForSchedule(FarmTaskSchedule $schedule): void
    {
        ScheduledNotification::query()
            ->where('status', ScheduledNotification::STATUS_PENDING)
            ->whereIn('instance_id', $schedule->instances()->select('id'))
            ->delete();
    }

    /**
     * @param  list<array{offset_minutes: int, label?: string|null}>|list<int>  $reminders
     * @return list<array{offset_minutes: int, label: string|null}>
     */
    public function normalizeReminders(array $reminders): array
    {
        $max = (int) config('notifications.reminders.max_per_task', 5);

        return collect($reminders)
            ->map(function ($reminder) {
                if (is_array($reminder)) {
                    return [
                        'offset_minutes' => max(0, (int) ($reminder['offset_minutes'] ?? 0)),
                        'label' => $reminder['label'] ?? null,
                    ];
                }

                return ['offset_minutes' => max(0, (int) $reminder), 'label' => null];
            })
            ->unique('offset_minutes')
            ->sortByDesc('offset_minutes')
            ->take($max)
            ->values()
            ->all();
    }
}
