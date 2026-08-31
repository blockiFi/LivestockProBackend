<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ChecksEquipmentAccess;
use App\Models\Equipment;
use App\Models\EquipmentInspection;
use App\Services\Equipment\EquipmentActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EquipmentInspectionController extends ApiController
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

        $items = $equipment->inspections()
            ->with(['inspector:id,name'])
            ->orderByDesc('inspection_date')
            ->get();

        return $this->sendResponse($items, 'Inspections retrieved');
    }

    public function store(Request $request, $farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm) && !$this->canViewEquipment($farm)) {
            return $this->denyManage();
        }
        $this->ensureEquipment($farm, $equipment);

        $validator = Validator::make($request->all(), [
            'inspection_date' => 'required|date',
            'inspector_user_id' => 'nullable|exists:users,id',
            'condition' => 'nullable|in:' . implode(',', Equipment::CONDITIONS),
            'findings' => 'nullable|string',
            'problems_identified' => 'nullable|string',
            'recommended_action' => 'nullable|string',
            'notes' => 'nullable|string',
            'next_inspection_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $inspection = EquipmentInspection::create([
            ...$data,
            'farm_id' => $farm->id,
            'equipment_id' => $equipment->id,
            'inspector_user_id' => $data['inspector_user_id'] ?? auth()->id(),
            'created_by' => auth()->id(),
        ]);

        $equipment->update([
            'last_inspection_date' => $data['inspection_date'],
            'next_inspection_date' => $data['next_inspection_date'] ?? $equipment->next_inspection_date,
            'condition' => $data['condition'] ?? $equipment->condition,
            'updated_by' => auth()->id(),
        ]);

        $this->activity->log(
            $equipment,
            'inspection',
            'Inspection recorded — condition: ' . ($data['condition'] ?? 'unchanged'),
            auth()->user(),
            ['inspection_id' => $inspection->id]
        );

        return $this->sendResponse($inspection->load('inspector'), 'Inspection recorded', 201);
    }

    protected function ensureEquipment($farm, Equipment $equipment): void
    {
        if ((int) $equipment->farm_id !== (int) $farm->id) {
            abort(response()->json(['success' => false, 'message' => 'Equipment not found'], 404));
        }
    }
}
