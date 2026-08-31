<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ChecksEquipmentAccess;
use App\Models\Equipment;
use App\Models\EquipmentDocument;
use App\Models\EquipmentRetirement;
use App\Models\EquipmentUsageLog;
use App\Services\Equipment\EquipmentActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class EquipmentLifecycleController extends ApiController
{
    use ChecksEquipmentAccess;

    public function __construct(protected EquipmentActivityService $activity)
    {
    }

    public function documents($farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canViewEquipment($farm)) {
            return $this->denyView();
        }
        $this->ensureEquipment($farm, $equipment);

        return $this->sendResponse(
            $equipment->documents()->with('uploader:id,name')->latest()->get(),
            'Documents retrieved'
        );
    }

    public function storeDocument(Request $request, $farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }
        $this->ensureEquipment($farm, $equipment);

        $validator = Validator::make($request->all(), [
            'document_type' => 'nullable|string|max:32',
            'name' => 'required|string|max:255',
            'file' => 'required|file|max:10240',
            'expires_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $file = $request->file('file');
        $path = $file->store("equipment/{$farm->id}/{$equipment->id}", 'public');

        $doc = EquipmentDocument::create([
            'farm_id' => $farm->id,
            'equipment_id' => $equipment->id,
            'document_type' => $request->input('document_type', 'other'),
            'name' => $request->name,
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'expires_at' => $request->expires_at,
            'uploaded_by' => auth()->id(),
        ]);

        $this->activity->log($equipment, 'document', 'Document uploaded: ' . $doc->name, auth()->user());

        return $this->sendResponse($doc->load('uploader'), 'Document uploaded', 201);
    }

    public function destroyDocument($farm, Equipment $equipment, EquipmentDocument $document)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }
        $this->ensureEquipment($farm, $equipment);

        if ((int) $document->equipment_id !== (int) $equipment->id) {
            return $this->sendNotFoundError('Document not found');
        }

        Storage::disk('public')->delete($document->storage_path);
        $document->delete();

        return $this->sendResponse(null, 'Document deleted');
    }

    public function recordUsage(Request $request, $farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }
        $this->ensureEquipment($farm, $equipment);

        $validator = Validator::make($request->all(), [
            'metric' => 'required|string|max:32',
            'value' => 'required|numeric|min:0',
            'recorded_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $previous = (float) ($equipment->current_usage_value ?? 0);
        $value = (float) $request->value;

        $log = EquipmentUsageLog::create([
            'farm_id' => $farm->id,
            'equipment_id' => $equipment->id,
            'metric' => $request->metric,
            'value' => $value,
            'delta' => $value - $previous,
            'recorded_at' => $request->input('recorded_at', now()->toDateString()),
            'notes' => $request->notes,
            'recorded_by' => auth()->id(),
        ]);

        $equipment->update([
            'usage_metric' => $request->metric,
            'current_usage_value' => $value,
            'updated_by' => auth()->id(),
        ]);

        $this->activity->log(
            $equipment,
            'usage',
            "Usage recorded: {$value} {$request->metric}",
            auth()->user(),
            ['usage_log_id' => $log->id]
        );

        return $this->sendResponse($log, 'Usage recorded', 201);
    }

    public function retire(Request $request, $farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }
        $this->ensureEquipment($farm, $equipment);

        $validator = Validator::make($request->all(), [
            'disposal_method' => 'required|in:retired,sold,scrapped,donated,disposed,lost',
            'disposal_date' => 'required|date',
            'reason' => 'nullable|string',
            'final_condition' => 'nullable|in:' . implode(',', Equipment::CONDITIONS),
            'sale_price' => 'nullable|numeric|min:0',
            'buyer_recipient' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $statusMap = [
            'retired' => 'retired',
            'sold' => 'retired',
            'scrapped' => 'disposed',
            'donated' => 'disposed',
            'disposed' => 'disposed',
            'lost' => 'lost_missing',
        ];

        $retirement = EquipmentRetirement::updateOrCreate(
            ['equipment_id' => $equipment->id],
            [
                ...$validator->validated(),
                'farm_id' => $farm->id,
                'authorized_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]
        );

        $equipment->update([
            'status' => $statusMap[$request->disposal_method] ?? 'retired',
            'condition' => $request->final_condition ?? $equipment->condition,
            'updated_by' => auth()->id(),
        ]);

        $this->activity->log(
            $equipment,
            'retired',
            'Equipment retired/disposed (' . $request->disposal_method . ')',
            auth()->user(),
            ['retirement_id' => $retirement->id]
        );

        return $this->sendResponse($retirement->load('authorizer'), 'Equipment retired', 201);
    }

    protected function ensureEquipment($farm, Equipment $equipment): void
    {
        if ((int) $equipment->farm_id !== (int) $farm->id) {
            abort(response()->json(['success' => false, 'message' => 'Equipment not found'], 404));
        }
    }
}
