<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\Flock;
use App\Services\FarmAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class FlockNotificationController extends ApiController
{
    public function __construct(
        private readonly FarmAlertService $alertService
    ) {
    }

    /**
     * Get upcoming activities and low stock alerts for a specific flock.
     *
     * - Upcoming activities: medication & vaccination batch schedule items
     *   within the reminder window for this flock.
     * - Low stock: medication, vaccine and feed inventories that are low in stock
     *   for the same farm.
     */
    public function index(Request $request, $farmId, $flockId)
    {
        $validator = Validator::make(
            ['farm_id' => $farmId, 'flock_id' => $flockId],
            [
                'farm_id' => 'required|exists:farms,id',
                'flock_id' => 'required|exists:flocks,id',
            ]
        );

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid identifiers', $validator->errors()->toArray());
        }

        $farm = Farm::findOrFail($farmId);
        $flock = Flock::findOrFail($flockId);

        if ($flock->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock not found in this farm');
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (! auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendUnauthorizedError('You do not have permission to view flock notifications');
        }

        $payload = $this->alertService->forFlockLegacy($farm, $flock);

        return $this->sendResponse($payload, 'Flock notifications retrieved successfully');
    }
}
