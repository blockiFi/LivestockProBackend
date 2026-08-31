<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ChecksEquipmentAccess;
use App\Models\Equipment;
use App\Models\EquipmentAssignment;
use App\Models\EquipmentSetting;
use App\Models\EquipmentTransfer;
use App\Models\Farm;
use App\Services\Equipment\EquipmentActivityService;
use App\Services\Equipment\EquipmentAssetIdService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EquipmentController extends ApiController
{
    use ChecksEquipmentAccess;

    public function __construct(
        protected EquipmentAssetIdService $assetIds,
        protected EquipmentActivityService $activity,
    ) {
    }

    public function dashboard(Request $request, $farm)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canViewEquipment($farm)) {
            return $this->denyView();
        }

        $base = Equipment::query()->where('farm_id', $farm->id);
        $this->applyWorkerScope($base, $farm);

        $stats = [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->activeAssets()->count(),
            'in_use' => (clone $base)->where('status', 'in_use')->count(),
            'available' => (clone $base)->where('status', 'available')->count(),
            'under_maintenance' => (clone $base)->where('status', 'under_maintenance')->count(),
            'damaged' => (clone $base)->where('status', 'damaged')->count(),
            'retired' => (clone $base)->where('status', 'retired')->count(),
            'lost_missing' => (clone $base)->where('status', 'lost_missing')->count(),
            'total_purchase_value' => (float) (clone $base)->sum('purchase_price'),
            'requiring_maintenance' => (clone $base)->activeAssets()
                ->whereNotNull('next_maintenance_date')
                ->whereDate('next_maintenance_date', '<=', now()->addDays(7))
                ->count(),
            'expiring_warranty' => (clone $base)->activeAssets()
                ->whereNotNull('warranty_expires_at')
                ->whereDate('warranty_expires_at', '<=', now()->addDays(30))
                ->whereDate('warranty_expires_at', '>=', now())
                ->count(),
            'purchased_this_month' => (clone $base)
                ->whereMonth('purchase_date', now()->month)
                ->whereYear('purchase_date', now()->year)
                ->count(),
        ];

        $recentActivity = \App\Models\EquipmentActivityLog::query()
            ->where('farm_id', $farm->id)
            ->with(['equipment:id,asset_id,name', 'actor:id,name'])
            ->latest()
            ->limit(10)
            ->get();

        $upcomingMaintenance = (clone $base)->activeAssets()
            ->whereNotNull('next_maintenance_date')
            ->whereDate('next_maintenance_date', '>=', now())
            ->orderBy('next_maintenance_date')
            ->limit(8)
            ->get(['id', 'asset_id', 'name', 'next_maintenance_date', 'status']);

        $recentAssignments = EquipmentAssignment::query()
            ->where('farm_id', $farm->id)
            ->with(['equipment:id,asset_id,name', 'assignee:id,name'])
            ->latest('assigned_at')
            ->limit(8)
            ->get();

        $recentRetirements = Equipment::query()
            ->where('farm_id', $farm->id)
            ->whereIn('status', ['retired', 'disposed', 'lost_missing'])
            ->with('retirement')
            ->latest('updated_at')
            ->limit(8)
            ->get(['id', 'asset_id', 'name', 'status', 'updated_at']);

        $byCategory = (clone $base)->select('category_id', DB::raw('count(*) as count'))
            ->groupBy('category_id')
            ->with('category:id,name')
            ->get();

        $byStatus = (clone $base)->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        $byCondition = (clone $base)->select('condition', DB::raw('count(*) as count'))
            ->groupBy('condition')
            ->get();

        $valueByCategory = (clone $base)->select('category_id', DB::raw('sum(purchase_price) as total'))
            ->groupBy('category_id')
            ->with('category:id,name')
            ->get();

        return $this->sendResponse([
            'stats' => $stats,
            'recent_activity' => $recentActivity,
            'upcoming_maintenance' => $upcomingMaintenance,
            'recent_assignments' => $recentAssignments,
            'recent_retirements' => $recentRetirements,
            'charts' => [
                'by_category' => $byCategory,
                'by_status' => $byStatus,
                'by_condition' => $byCondition,
                'value_by_category' => $valueByCategory,
            ],
        ], 'Equipment dashboard retrieved');
    }

    public function index(Request $request, $farm)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canViewEquipment($farm)) {
            return $this->denyView();
        }

        $query = Equipment::query()
            ->where('farm_id', $farm->id)
            ->with(['category:id,name', 'assignee:id,name,email']);

        $this->applyWorkerScope($query, $farm);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('asset_id', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('supplier', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        foreach (['category_id', 'status', 'condition', 'assigned_to_user_id', 'department'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('farm_section')) {
            $query->where('farm_section', $request->farm_section);
        }
        if ($request->filled('location')) {
            $query->where('location', 'like', '%' . $request->location . '%');
        }
        if ($request->filled('supplier')) {
            $query->where('supplier', 'like', '%' . $request->supplier . '%');
        }
        if ($request->filled('purchase_from')) {
            $query->whereDate('purchase_date', '>=', $request->purchase_from);
        }
        if ($request->filled('purchase_to')) {
            $query->whereDate('purchase_date', '<=', $request->purchase_to);
        }

        if ($request->filled('warranty_status')) {
            match ($request->warranty_status) {
                'active' => $query->whereDate('warranty_expires_at', '>=', now()),
                'expiring' => $query->whereDate('warranty_expires_at', '<=', now()->addDays(30))
                    ->whereDate('warranty_expires_at', '>=', now()),
                'expired' => $query->whereDate('warranty_expires_at', '<', now()),
                default => null,
            };
        }

        if ($request->filled('maintenance_status')) {
            match ($request->maintenance_status) {
                'due' => $query->whereDate('next_maintenance_date', '<=', now()),
                'upcoming' => $query->whereDate('next_maintenance_date', '>', now())
                    ->whereDate('next_maintenance_date', '<=', now()->addDays(14)),
                default => null,
            };
        }

        $sortField = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_direction', 'desc');
        $allowedSort = ['asset_id', 'name', 'status', 'condition', 'purchase_date', 'purchase_price', 'created_at'];
        if (in_array($sortField, $allowedSort, true)) {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min(100, max(5, (int) $request->input('per_page', 15)));
            $items = $query->paginate($perPage);
        } else {
            $items = $query->get();
        }

        if (!$this->canViewFinancials($farm)) {
            $this->stripFinancials($items);
        }

        return $this->sendResponse($items, 'Equipment list retrieved');
    }

    public function store(Request $request, $farm)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }

        $validator = Validator::make($request->all(), $this->equipmentRules());
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $data['farm_id'] = $farm->id;
        $data['asset_id'] = $this->assetIds->nextAssetId($farm);
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();
        $data['status'] = $data['status'] ?? 'available';

        if (!empty($data['warranty_period_months']) && !empty($data['purchase_date']) && empty($data['warranty_expires_at'])) {
            $data['warranty_expires_at'] = \Carbon\Carbon::parse($data['purchase_date'])
                ->addMonths((int) $data['warranty_period_months'])
                ->toDateString();
        }

        $equipment = Equipment::create($data);

        $this->activity->log(
            $equipment,
            'created',
            'Equipment purchased and registered',
            auth()->user(),
            ['asset_id' => $equipment->asset_id, 'status' => $equipment->status]
        );

        $equipment->load(['category', 'assignee']);

        return $this->sendResponse($equipment, 'Equipment created', 201);
    }

    public function show(Request $request, $farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canViewEquipment($farm)) {
            return $this->denyView();
        }
        if ((int) $equipment->farm_id !== (int) $farm->id) {
            return $this->sendNotFoundError('Equipment not found');
        }
        if (!$this->canManageEquipment($farm) && (int) $equipment->assigned_to_user_id !== (int) auth()->id()) {
            return $this->denyView();
        }

        $equipment->load([
            'category',
            'assignee:id,name,email',
            'poultryHouse:id,name',
            'creator:id,name',
            'assignments.assignee:id,name',
            'assignments.assignedBy:id,name',
            'transfers.previousAssignee:id,name',
            'transfers.newAssignee:id,name',
            'transfers.transferredBy:id,name',
            'maintenanceLogs.performer:id,name',
            'inspections.inspector:id,name',
            'documents.uploader:id,name',
            'usageLogs.recorder:id,name',
            'retirement.authorizer:id,name',
            'activityLogs.actor:id,name',
        ]);

        if (!$this->canViewFinancials($farm)) {
            unset($equipment->purchase_price, $equipment->total_maintenance_cost, $equipment->total_repair_cost, $equipment->total_other_cost);
            $equipment->makeHidden(['purchase_price', 'total_maintenance_cost', 'total_repair_cost', 'total_other_cost', 'total_cost']);
        }

        $equipment->profile_url = config('notifications.frontend_url') . '/dashboard/equipment?asset=' . urlencode($equipment->asset_id);

        return $this->sendResponse($equipment, 'Equipment retrieved');
    }

    public function update(Request $request, $farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }
        if ((int) $equipment->farm_id !== (int) $farm->id) {
            return $this->sendNotFoundError('Equipment not found');
        }

        $validator = Validator::make($request->all(), $this->equipmentRules(isUpdate: true));
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $changes = [];
        foreach ($validator->validated() as $key => $value) {
            if ($equipment->{$key} != $value) {
                $changes[$key] = ['from' => $equipment->{$key}, 'to' => $value];
            }
            $equipment->{$key} = $value;
        }

        $equipment->updated_by = auth()->id();
        $equipment->save();

        if (isset($changes['status'])) {
            $this->activity->log(
                $equipment,
                'status_changed',
                'Status changed from ' . ($changes['status']['from'] ?? '-') . ' to ' . ($changes['status']['to'] ?? '-'),
                auth()->user(),
                $changes['status']
            );
        }
        if (isset($changes['condition'])) {
            $this->activity->log(
                $equipment,
                'condition_changed',
                'Condition updated to ' . ($changes['condition']['to'] ?? '-'),
                auth()->user(),
                $changes['condition']
            );
        }
        if ($changes) {
            $this->activity->log($equipment, 'updated', 'Equipment details updated', auth()->user(), $changes);
        }

        return $this->sendResponse($equipment->fresh(['category', 'assignee']), 'Equipment updated');
    }

    public function destroy($farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }
        if ((int) $equipment->farm_id !== (int) $farm->id) {
            return $this->sendNotFoundError('Equipment not found');
        }

        $equipment->delete();
        $this->activity->log($equipment, 'archived', 'Equipment archived', auth()->user());

        return $this->sendResponse(null, 'Equipment archived');
    }

    public function assign(Request $request, $farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }
        if ((int) $equipment->farm_id !== (int) $farm->id) {
            return $this->sendNotFoundError('Equipment not found');
        }

        $validator = Validator::make($request->all(), [
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'farm_section' => 'nullable|string|max:64',
            'location' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'poultry_house_id' => 'nullable|exists:poultry_houses,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        EquipmentAssignment::where('equipment_id', $equipment->id)->where('is_current', true)
            ->update(['is_current' => false, 'released_at' => now()]);

        $assignment = EquipmentAssignment::create([
            'farm_id' => $farm->id,
            'equipment_id' => $equipment->id,
            'assigned_to_user_id' => $request->assigned_to_user_id,
            'farm_section' => $request->farm_section,
            'location' => $request->location,
            'department' => $request->department,
            'poultry_house_id' => $request->poultry_house_id,
            'assigned_at' => now(),
            'assigned_by' => auth()->id(),
            'notes' => $request->notes,
            'is_current' => true,
        ]);

        $equipment->fill([
            'assigned_to_user_id' => $request->assigned_to_user_id,
            'farm_section' => $request->farm_section,
            'location' => $request->location,
            'department' => $request->department,
            'poultry_house_id' => $request->poultry_house_id,
            'assigned_at' => now(),
            'status' => $request->assigned_to_user_id ? 'assigned' : ($equipment->status === 'available' ? 'available' : $equipment->status),
        ])->save();

        $assigneeName = $assignment->assignee?->name ?? 'Unassigned';
        $this->activity->log(
            $equipment,
            'assigned',
            'Equipment assigned to ' . $assigneeName,
            auth()->user(),
            ['assignment_id' => $assignment->id]
        );

        return $this->sendResponse($assignment->load(['assignee', 'assignedBy']), 'Equipment assigned');
    }

    public function transfer(Request $request, $farm, Equipment $equipment)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }
        if ((int) $equipment->farm_id !== (int) $farm->id) {
            return $this->sendNotFoundError('Equipment not found');
        }

        $validator = Validator::make($request->all(), [
            'new_location' => 'nullable|string|max:255',
            'new_section' => 'nullable|string|max:64',
            'new_department' => 'nullable|string|max:255',
            'new_assignee_id' => 'nullable|exists:users,id',
            'new_house_id' => 'nullable|exists:poultry_houses,id',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $transfer = EquipmentTransfer::create([
            'farm_id' => $farm->id,
            'equipment_id' => $equipment->id,
            'previous_location' => $equipment->location,
            'new_location' => $request->new_location,
            'previous_section' => $equipment->farm_section,
            'new_section' => $request->new_section,
            'previous_department' => $equipment->department,
            'new_department' => $request->new_department,
            'previous_assignee_id' => $equipment->assigned_to_user_id,
            'new_assignee_id' => $request->new_assignee_id,
            'previous_house_id' => $equipment->poultry_house_id,
            'new_house_id' => $request->new_house_id,
            'transferred_at' => now(),
            'transferred_by' => auth()->id(),
            'reason' => $request->reason,
            'notes' => $request->notes,
        ]);

        $equipment->update([
            'location' => $request->new_location ?? $equipment->location,
            'farm_section' => $request->new_section ?? $equipment->farm_section,
            'department' => $request->new_department ?? $equipment->department,
            'assigned_to_user_id' => $request->new_assignee_id ?? $equipment->assigned_to_user_id,
            'poultry_house_id' => $request->new_house_id ?? $equipment->poultry_house_id,
            'updated_by' => auth()->id(),
        ]);

        $this->activity->log(
            $equipment,
            'transferred',
            'Equipment transferred',
            auth()->user(),
            ['transfer_id' => $transfer->id, 'reason' => $request->reason]
        );

        return $this->sendResponse($transfer->load(['previousAssignee', 'newAssignee', 'transferredBy']), 'Equipment transferred');
    }

    public function bulkUpdate(Request $request, $farm)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }

        $validator = Validator::make($request->all(), [
            'equipment_ids' => 'required|array|min:1',
            'equipment_ids.*' => 'integer|exists:equipment,id',
            'action' => 'required|in:status,category,location,assign',
            'status' => 'required_if:action,status|in:' . implode(',', Equipment::STATUSES),
            'category_id' => 'required_if:action,category|exists:equipment_categories,id',
            'location' => 'required_if:action,location|nullable|string|max:255',
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'farm_section' => 'nullable|string|max:64',
            'department' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $items = Equipment::where('farm_id', $farm->id)
            ->whereIn('id', $request->equipment_ids)
            ->get();

        $updated = 0;
        foreach ($items as $equipment) {
            if ($request->action === 'assign') {
                EquipmentAssignment::where('equipment_id', $equipment->id)->where('is_current', true)
                    ->update(['is_current' => false, 'released_at' => now()]);
                EquipmentAssignment::create([
                    'farm_id' => $farm->id,
                    'equipment_id' => $equipment->id,
                    'assigned_to_user_id' => $request->assigned_to_user_id,
                    'farm_section' => $request->farm_section,
                    'location' => $request->location,
                    'department' => $request->department,
                    'assigned_at' => now(),
                    'assigned_by' => auth()->id(),
                    'is_current' => true,
                ]);
                $equipment->update([
                    'assigned_to_user_id' => $request->assigned_to_user_id,
                    'farm_section' => $request->farm_section,
                    'location' => $request->location,
                    'department' => $request->department,
                    'assigned_at' => now(),
                    'status' => $request->assigned_to_user_id ? 'assigned' : $equipment->status,
                    'updated_by' => auth()->id(),
                ]);
            } else {
                match ($request->action) {
                    'status' => $equipment->update(['status' => $request->status, 'updated_by' => auth()->id()]),
                    'category' => $equipment->update(['category_id' => $request->category_id, 'updated_by' => auth()->id()]),
                    'location' => $equipment->update([
                        'location' => $request->location,
                        'farm_section' => $request->farm_section,
                        'department' => $request->department,
                        'updated_by' => auth()->id(),
                    ]),
                    default => null,
                };
            }
            $updated++;
        }

        return $this->sendResponse(['updated' => $updated], 'Bulk update completed');
    }

    public function getSettings($farm)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }

        return $this->sendResponse(
            $this->assetIds->settingsFor($farm),
            'Equipment settings retrieved'
        );
    }

    public function updateSettings(Request $request, $farm)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }

        $validator = Validator::make($request->all(), [
            'asset_id_prefix' => 'nullable|string|max:16',
            'warranty_reminder_days' => 'nullable|array',
            'maintenance_reminder_days' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $settings = $this->assetIds->settingsFor($farm);
        $settings->fill($validator->validated())->save();

        return $this->sendResponse($settings, 'Equipment settings updated');
    }

    protected function equipmentRules(bool $isUpdate = false): array
    {
        $sometimes = $isUpdate ? 'sometimes|' : '';

        return [
            'category_id' => $sometimes . 'nullable|exists:equipment_categories,id',
            'name' => $sometimes . 'required|string|max:255',
            'equipment_type' => 'nullable|string|max:120',
            'brand' => 'nullable|string|max:120',
            'model' => 'nullable|string|max:120',
            'serial_number' => 'nullable|string|max:120',
            'description' => 'nullable|string',
            'quantity' => 'nullable|integer|min:1',
            'unit' => 'nullable|string|max:32',
            'purchase_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'invoice_reference' => 'nullable|string|max:255',
            'purchase_order_number' => 'nullable|string|max:255',
            'payment_status' => 'nullable|string|max:32',
            'warranty_period_months' => 'nullable|integer|min:0',
            'warranty_expires_at' => 'nullable|date',
            'farm_section' => 'nullable|string|max:64',
            'location' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'poultry_house_id' => 'nullable|exists:poultry_houses,id',
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:' . implode(',', Equipment::STATUSES),
            'condition' => 'nullable|in:' . implode(',', Equipment::CONDITIONS),
            'placed_in_service_date' => 'nullable|date',
            'expected_useful_life_months' => 'nullable|integer|min:0',
            'current_usage_value' => 'nullable|numeric|min:0',
            'usage_metric' => 'nullable|string|max:32',
            'last_inspection_date' => 'nullable|date',
            'next_inspection_date' => 'nullable|date',
            'maintenance_interval_days' => 'nullable|integer|min:0',
            'next_maintenance_date' => 'nullable|date',
            'last_maintenance_date' => 'nullable|date',
        ];
    }

    protected function stripFinancials($items): void
    {
        $collection = method_exists($items, 'getCollection') ? $items->getCollection() : collect($items);
        $collection->each(function ($item) {
            $item->makeHidden([
                'purchase_price',
                'total_maintenance_cost',
                'total_repair_cost',
                'total_other_cost',
                'total_cost',
            ]);
        });
    }
}
