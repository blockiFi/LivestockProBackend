<?php

namespace App\Services\Notifications;

use App\Models\Farm;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPriority;
use App\Notifications\NotificationType;
use App\Services\FarmAlertService;

/**
 * Turns computed farm alerts (stock, health, upcoming schedules) into
 * first-class in-app and email notifications through the central service.
 *
 * Deduped per farm + alert id so hourly sweeps never spam the same warning.
 */
class FarmOperationsNotifier
{
    public const RECIPIENT_PERMISSIONS = ['view farm', 'manage farm settings', 'manage farm tasks'];

    public function __construct(
        protected NotificationService $notifications,
        protected FarmAlertService $alerts,
    ) {
    }

    public function dispatchForFarm(Farm $farm): int
    {
        $payload = $this->alerts->forFarm($farm);
        $sent = 0;

        foreach ($payload['items'] ?? [] as $alert) {
            $type = $this->typeFor($alert);
            if (!$type) {
                continue;
            }

            $created = $this->notifications->send(
                NotificationMessage::make($type)
                    ->farm($farm)
                    ->toFarmMembersWithPermission(...self::RECIPIENT_PERMISSIONS)
                    ->title((string) ($alert['title'] ?? $type))
                    ->body((string) ($alert['detail'] ?? ''))
                    ->action($alert['link'] ?? null, 'Open')
                    ->priority($this->priorityFor((string) ($alert['severity'] ?? 'info')))
                    ->section($alert['flock_name'] ?? null)
                    ->dedupe('farm_alert:' . $farm->id . ':' . ($alert['id'] ?? md5(json_encode($alert))))
                    ->payload([
                        'alert_id' => $alert['id'] ?? null,
                        'severity' => $alert['severity'] ?? null,
                        'flock_id' => $alert['flock_id'] ?? null,
                    ])
                    ->with([
                        'farm_name' => $farm->name,
                        'flock_name' => $alert['flock_name'] ?? null,
                    ])
            );

            $sent += $created->count();
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    protected function typeFor(array $alert): ?string
    {
        $category = (string) ($alert['category'] ?? '');
        $title = strtolower((string) ($alert['title'] ?? ''));

        return match ($category) {
            'low_stock' => NotificationType::LOW_STOCK_ALERT,
            'expiring' => NotificationType::INVENTORY_ALERT,
            'mortality_spike' => NotificationType::ANIMAL_HEALTH_ALERT,
            'upcoming_schedule' => $this->scheduleType($title),
            default => NotificationType::FARM_ACTIVITY_ALERT,
        };
    }

    protected function scheduleType(string $title): string
    {
        if (str_contains($title, 'vaccin')) {
            return NotificationType::VACCINATION_REMINDER;
        }
        if (str_contains($title, 'medicat')) {
            return NotificationType::MEDICATION_REMINDER;
        }

        return NotificationType::FEEDING_REMINDER;
    }

    protected function priorityFor(string $severity): string
    {
        return match ($severity) {
            'critical' => NotificationPriority::CRITICAL,
            'warning' => NotificationPriority::HIGH,
            default => NotificationPriority::NORMAL,
        };
    }
}
