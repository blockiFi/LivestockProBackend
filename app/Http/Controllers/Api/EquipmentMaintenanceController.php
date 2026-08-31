<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ChecksEquipmentAccess;
use App\Models\Equipment;
use App\Models\EquipmentMaintenanceLog;
use App\Services\Equipment\EquipmentActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EquipmentMaintenanceController extends ApiController
{
    use ChecksEquipmentAccess;

    public function __construct(protected EquipmentActivityService $activity)
    {
    }

    public function index($farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canViewEquipment($farm)) {
            return $this->denyView();
        }
        $this->ensureEquipment($farm, $equipment);

        $logs = $equipment->maintenanceLogs()
            ->with(['performer:id,name'])
            ->orderByDesc('performed_at')
            ->get();

        return $this->sendResponse($logs, 'Maintenance logs retrieved');
    }

    public function store(Request $request, $farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm) && !$this->canViewEquipment($farm)) {
            return $this->denyManage();
        }
        $this->ensureEquipment($farm, $equipment);

        $validator = Validator::make($request->all(), [
            'maintenance_type' => 'nullable|string|max:32',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'performed_at' => 'required|date',
            'next_due_at' => 'nullable|date',
            'service_provider' => 'nullable|string|max:255',
            'technician' => 'nullable|string|max:255',
            'parts_replaced' => 'nullable|string',
            'labour_cost' => 'nullable|numeric|min:0',
            'parts_cost' => 'nullable|numeric|min:0',
            'total_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $labour = (float) ($data['labour_cost'] ?? 0);
        $parts = (float) ($data['parts_cost'] ?? 0);
        $total = isset($data['total_cost']) ? (float) $data['total_cost'] : ($labour + $parts);

        $log = EquipmentMaintenanceLog::create([
            ...$data,
            'farm_id' => $farm->id,
            'equipment_id' => $equipment->id,
            'total_cost' => $total,
            'performed_by_user_id' => auth()->id(),
            'created_by' => auth()->id(),
        ]);

        $equipment->update([
            'last_maintenance_date' => $data['performed_at'],
            'next_maintenance_date' => $data['next_due_at'] ?? ($equipment->maintenance_interval_days
                ? \Carbon\Carbon::parse($data['performed_at'])->addDays($equipment->maintenance_interval_days)->toDateString()
                : $equipment->next_maintenance_date),
            'total_maintenance_cost' => (float) $equipment->total_maintenance_cost + $total,
            'status' => $equipment->status === 'under_maintenance' ? 'available' : $equipment->status,
            'updated_by' => auth()->id(),
        ]);

        $this->activity->log(
            $equipment,
            'maintenance',
            'Maintenance completed: ' . ($data['title'] ?? $data['maintenance_type'] ?? 'Service'),
            auth()->user(),
            ['maintenance_id' => $log->id, 'total_cost' => $total]
        );

        return $this->sendResponse($log->load('performer'), 'Maintenance recorded', 201);
    }

    protected function ensureEquipment($farm, Equipment $equipment): void
    {
        if ((int) $equipment->farm_id !== (int) $farm->id) {
            abort(response()->json(['success' => false, 'message' => 'Equipment not found'], 404));
        }
    }
}
