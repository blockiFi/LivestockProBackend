<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockHouseAllocation;
use App\Models\FlockTransfer;
use App\Services\FlockTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class FlockTransferController extends ApiController
{
    public function allocations(Request $request, $farmId, $flockId)
    {
        $validator = Validator::make(['farm_id' => $farmId, 'flock_id' => $flockId], [
            'farm_id' => 'required|exists:farms,id',
            'flock_id' => 'required|exists:flocks,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid request', $validator->errors()->all());
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);
        $flock->reconcileHouseAllocations();

        $allocations = FlockHouseAllocation::with(['house'])
            ->where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->orderBy('house_id')
            ->get();

        // Legacy fallback: if no allocations exist, report the flock's single house with current birds
        if ($allocations->count() === 0 && $flock->house_id) {
            $allocations = collect([[
                'farm_id' => $farmId,
                'flock_id' => $flockId,
                'house_id' => $flock->house_id,
                'quantity' => $flock->actual_quantity,
                'house' => $flock->poultryHouse,
            ]]);
        }

        return $this->sendResponse($allocations, 'Flock allocations retrieved successfully');
    }

    public function history(Request $request, $farmId, $flockId)
    {
        $validator = Validator::make(['farm_id' => $farmId, 'flock_id' => $flockId], [
            'farm_id' => 'required|exists:farms,id',
            'flock_id' => 'required|exists:flocks,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid request', $validator->errors()->all());
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        Flock::where('farm_id', $farmId)->findOrFail($flockId);

        $transfers = FlockTransfer::with(['lines.fromHouse', 'lines.toHouse', 'createdBy'])
            ->where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->orderBy('transfer_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return $this->sendResponse($transfers, 'Flock transfer history retrieved successfully');
    }

    public function store(Request $request, $farmId, $flockId, FlockTransferService $service)
    {
        $validator = Validator::make(array_merge($request->all(), [
            'farm_id' => $farmId,
            'flock_id' => $flockId,
        ]), [
            'farm_id' => 'required|exists:farms,id',
            'flock_id' => 'required|exists:flocks,id',
            'transfer_date' => 'required|date',
            'note' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.from_house_id' => 'nullable|exists:poultry_houses,id',
            'lines.*.to_house_id' => 'nullable|exists:poultry_houses,id',
            'lines.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid transfer data', $validator->errors()->all());
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage flocks', [], 403);
        }

        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $transfer = $service->apply($farm, $flock, $validator->validated(), auth()->id());

        return $this->sendResponse($transfer, 'Flock transfer applied successfully', 201);
    }
}

