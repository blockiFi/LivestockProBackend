<?php

namespace App\Services\Notifications;

use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Notifications\NotificationPriority;
use Carbon\CarbonImmutable;

/**
 * Escalates overdue tasks according to each farm's configured thresholds.
 *
 * Stage one notifies farm management, stage two raises the alert to critical.
 * Thresholds live in farm_notification_configs so the rules are configurable
 * rather than hard-coded, and each stage fires exactly once per task.
 */
class TaskEscalationService
{
    public const STAGE_MANAGER = 'manager_notified';
    public const STAGE_CRITICAL = 'raised_critical';

    public function __construct(protected FarmTaskNotifier $notifier)
    {
    }

    /**
     * @return array{escalated: int, evaluated: int}
     */
    public function run(int $farmId, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $farm = Farm::with('settings')->find($farmId);

        if (!$farm) {
            return ['escalated' => 0, 'evaluated' => 0];
        }

        $config = $farm->notificationConfigOrDefault();

        if (!$config->escalation_enabled || !config('notifications.escalation.enabled', true)) {
            return ['escalated' => 0, 'evaluated' => 0];
        }

        $timezone = $farm->resolveTimezone();
        $managerAfter = (int) $config->escalate_to_manager_after_minutes;
        $criticalAfter = (int) $config->escalate_high_priority_after_minutes;

        $escalated = 0;
        $evaluated = 0;

        FarmTaskInstance::query()
            ->with(['assignee'])
            ->where('farm_id', $farmId)
            ->where('status', 'overdue')
            ->whereDate('scheduled_date', '>=', $now->subDays(7)->toDateString())
            ->chunkById(200, function ($instances) use (&$escalated, &$evaluated, $now, $timezone, $managerAfter, $criticalAfter) {
                foreach ($instances as $instance) {
                    $evaluated++;
                    $overdueMinutes = $this->overdueMinutes($instance, $now, $timezone);

                    if ($overdueMinutes < $managerAfter) {
                        continue;
                    }

                    $this->notifier->escalateOverdue(
                        $instance,
                        self::STAGE_MANAGER,
                        NotificationPriority::HIGH,
                        $overdueMinutes
                    );
                    $escalated++;

                    if ($criticalAfter > 0 && $overdueMinutes >= $criticalAfter) {
                        $this->notifier->escalateOverdue(
                            $instance,
                            self::STAGE_CRITICAL,
                            NotificationPriority::CRITICAL,
                            $overdueMinutes
                        );
                        $escalated++;
                    }
                }
            });

        return ['escalated' => $escalated, 'evaluated' => $evaluated];
    }

    protected function overdueMinutes(FarmTaskInstance $instance, CarbonImmutable $now, string $timezone): int
    {
        $dueAt = $instance->scheduledDueAt($timezone);

        return max(0, (int) $dueAt->diffInMinutes($now, false));
    }
}
