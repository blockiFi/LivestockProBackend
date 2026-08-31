<?php

namespace App\Services\Notifications;

use App\Models\Equipment;
use App\Models\Farm;
use App\Models\User;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPriority;
use App\Notifications\NotificationType;
use Carbon\CarbonImmutable;

class EquipmentNotifier
{
    public const RECIPIENT_PERMISSIONS = ['view equipment', 'manage equipment'];

    public function __construct(protected NotificationService $notifications)
    {
    }

    public function maintenanceDue(Equipment $equipment, int $daysUntil): void
    {
        $priority = $daysUntil <= 1 ? NotificationPriority::HIGH : NotificationPriority::NORMAL;

        $this->notifications->send(
            $this->base($equipment, NotificationType::EQUIPMENT_MAINTENANCE_DUE)
                ->toFarmMembersWithPermission(...self::RECIPIENT_PERMISSIONS)
                ->title('Maintenance due: ' . $equipment->name)
                ->body($this->dueBody('maintenance', $equipment, $daysUntil))
                ->priority($priority)
                ->dedupe('equipment_maint_due:' . $equipment->id . ':d' . $daysUntil)
                ->with([
                    'equipment_name' => $equipment->name,
                    'asset_id' => $equipment->asset_id,
                    'due_date' => $equipment->next_maintenance_date?->format('D, d M Y'),
                    'days_until' => $daysUntil,
                ])
        );
    }

    public function warrantyExpiring(Equipment $equipment, int $daysUntil): void
    {
        $priority = $daysUntil <= 7 ? NotificationPriority::HIGH : NotificationPriority::NORMAL;

        $this->notifications->send(
            $this->base($equipment, NotificationType::EQUIPMENT_WARRANTY_EXPIRING)
                ->toFarmMembersWithPermission(...self::RECIPIENT_PERMISSIONS)
                ->title('Warranty expiring: ' . $equipment->name)
                ->body($this->dueBody('warranty', $equipment, $daysUntil))
                ->priority($priority)
                ->dedupe('equipment_warranty:' . $equipment->id . ':d' . $daysUntil)
                ->with([
                    'equipment_name' => $equipment->name,
                    'asset_id' => $equipment->asset_id,
                    'expiry_date' => $equipment->warranty_expires_at?->format('D, d M Y'),
                    'days_until' => $daysUntil,
                ])
        );
    }

    protected function base(Equipment $equipment, string $type): NotificationMessage
    {
        return NotificationMessage::make($type)
            ->farm($equipment->farm_id)
            ->source($equipment)
            ->section($equipment->farm_section)
            ->action('/dashboard/equipment?asset=' . urlencode($equipment->asset_id), 'View equipment');
    }

    protected function dueBody(string $kind, Equipment $equipment, int $daysUntil): string
    {
        if ($daysUntil <= 0) {
            return ucfirst($kind) . ' for ' . $equipment->asset_id . ' is due today.';
        }

        return ucfirst($kind) . ' for ' . $equipment->asset_id . ' is due in '
            . ($daysUntil === 1 ? '1 day' : $daysUntil . ' days') . '.';
    }
}
