<?php

namespace App\Services\Admin;

use App\Models\Farm;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryMedicationInventory;
use App\Models\PoultryVaccineInventory;
use Carbon\Carbon;

class AdminInventoryAlertService
{
    public function list(?int $farmId = null): array
    {
        $alerts = collect();

        $feedAlerts = $this->feedAlerts($farmId);
        $vaccineAlerts = $this->vaccineAlerts($farmId);
        $medicationAlerts = $this->medicationAlerts($farmId);

        return [
            'alerts' => $feedAlerts->concat($vaccineAlerts)->concat($medicationAlerts)->values()->all(),
            'summary' => [
                'feed_low_stock' => $feedAlerts->count(),
                'vaccine_expiring' => $vaccineAlerts->count(),
                'medication_low_stock' => $medicationAlerts->count(),
                'total' => $feedAlerts->count() + $vaccineAlerts->count() + $medicationAlerts->count(),
            ],
        ];
    }

    public function summary(?int $farmId = null): array
    {
        return $this->list($farmId)['summary'];
    }

    private function feedAlerts(?int $farmId)
    {
        $query = PoultryFeedInventory::with(['farm:id,name', 'feedType:id,name'])
            ->whereIn('status', ['available', 'in_use'])
            ->where('available_quantity', '<=', 10);

        if ($farmId) {
            $query->where('farm_id', $farmId);
        }

        return $query->get()->map(fn ($inv) => [
            'type' => 'feed_low_stock',
            'severity' => 'warning',
            'farm_id' => $inv->farm_id,
            'farm_name' => $inv->farm?->name,
            'resource' => $inv->feedType?->name ?? 'Feed',
            'message' => "Low feed stock: {$inv->available_quantity} remaining",
            'resource_id' => $inv->id,
        ]);
    }

    private function vaccineAlerts(?int $farmId)
    {
        $threshold = Carbon::now()->addDays(30);

        $query = PoultryVaccineInventory::with(['farm:id,name', 'product:id,name'])
            ->whereIn('status', ['available', 'in_use'])
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $threshold);

        if ($farmId) {
            $query->where('farm_id', $farmId);
        }

        return $query->get()->map(fn ($inv) => [
            'type' => 'vaccine_expiring',
            'severity' => Carbon::parse($inv->expiry_date)->isPast() ? 'critical' : 'warning',
            'farm_id' => $inv->farm_id,
            'farm_name' => $inv->farm?->name,
            'resource' => $inv->product?->name ?? 'Vaccine',
            'message' => "Vaccine expires: {$inv->expiry_date}",
            'resource_id' => $inv->id,
        ]);
    }

    private function medicationAlerts(?int $farmId)
    {
        $query = PoultryMedicationInventory::with(['farm:id,name', 'product:id,name'])
            ->whereIn('status', ['available', 'in_use'])
            ->where('available_quantity', '<=', 5);

        if ($farmId) {
            $query->where('farm_id', $farmId);
        }

        return $query->get()->map(fn ($inv) => [
            'type' => 'medication_low_stock',
            'severity' => 'warning',
            'farm_id' => $inv->farm_id,
            'farm_name' => $inv->farm?->name,
            'resource' => $inv->product?->name ?? 'Medication',
            'message' => "Low medication stock: {$inv->available_quantity} remaining",
            'resource_id' => $inv->id,
        ]);
    }
}
