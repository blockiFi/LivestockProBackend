<?php

namespace App\Services\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentSetting;
use App\Models\Farm;
use App\Services\Notifications\EquipmentNotifier;
use Carbon\CarbonImmutable;

class EquipmentAlertService
{
    public function __construct(protected EquipmentNotifier $notifier)
    {
    }

    public function dispatchForFarm(Farm $farm): array
    {
        $settings = EquipmentSetting::firstOrCreate(
            ['farm_id' => $farm->id],
            [
                'warranty_reminder_days' => [30, 14, 7, 0],
                'maintenance_reminder_days' => [7, 3, 1],
            ]
        );

        $today = CarbonImmutable::today();
        $maintenanceSent = 0;
        $warrantySent = 0;

        $assets = Equipment::query()
            ->where('farm_id', $farm->id)
            ->activeAssets()
            ->get();

        foreach ($assets as $equipment) {
            if ($equipment->next_maintenance_date) {
                $daysUntil = $today->diffInDays(CarbonImmutable::parse($equipment->next_maintenance_date), false);
                if (in_array((int) $daysUntil, $settings->maintenance_reminder_days ?? [7, 3, 1], true)) {
                    $sent = $this->notifier->maintenanceDue($equipment, (int) $daysUntil);
                    $maintenanceSent += $sent->count();
                }
            }

            if ($equipment->warranty_expires_at) {
                $daysUntil = $today->diffInDays(CarbonImmutable::parse($equipment->warranty_expires_at), false);
                if (in_array((int) $daysUntil, $settings->warranty_reminder_days ?? [30, 14, 7, 0], true)) {
                    $sent = $this->notifier->warrantyExpiring($equipment, (int) $daysUntil);
                    $warrantySent += $sent->count();
                }
            }
        }

        return [
            'maintenance_notifications' => $maintenanceSent,
            'warranty_notifications' => $warrantySent,
        ];
    }
}
