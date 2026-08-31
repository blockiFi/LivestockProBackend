<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\SalesRecord;
use App\Services\SalesProfitLossService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SalesRecordController extends ApiController
{
    public function __construct(
        private readonly SalesProfitLossService $profitLossService
    ) {
    }

    public function index(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $this->canViewSales($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view product sales');
        }

        $query = SalesRecord::with(['flock:id,name,batch_number', 'customer:id,name'])
            ->where('farm_id', $farmId);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('flock_id')) {
            $query->where('flock_id', $request->flock_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        $records = $query->orderByDesc('date')->orderByDesc('id')->get();

        return $this->sendResponse($records, 'Product sales retrieved successfully');
    }

    public function store(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('create sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create product sales');
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:egg,meat,manure',
            'flock_id' => 'nullable|exists:flocks,id',
            'quantity' => 'required|numeric|min:0.01',
            'unit_price' => 'required|numeric|min:0',
            'date' => 'required|date',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'payment_method' => 'nullable|string|max:50',
            'payment_status' => 'nullable|in:pending,paid,partial',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $type = $request->input('type');
        $flockId = $request->input('flock_id');

        if (in_array($type, ['egg', 'meat'], true) && ! $flockId) {
            return $this->sendValidationError('Validation failed', [
                'flock_id' => ['Flock is required for egg and meat sales.'],
            ]);
        }

        if ($flockId) {
            $flock = Flock::where('farm_id', $farmId)->find($flockId);
            if (! $flock) {
                return $this->sendValidationError('Validation failed', [
                    'flock_id' => ['Flock does not belong to this farm.'],
                ]);
            }
        }

        if ($type === 'egg' && $flockId) {
            $check = $this->profitLossService->validateEggSaleQuantity(
                (int) $farmId,
                (int) $flockId,
                $request->input('date'),
                (float) $request->input('quantity')
            );
            if (! $check['valid']) {
                return $this->sendError($check['message'], ['available' => $check['available'] ?? 0], 422);
            }
        }

        $quantity = round((float) $request->input('quantity'), 2);
        $unitPrice = round((float) $request->input('unit_price'), 2);

        $record = SalesRecord::create([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'type' => $type,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => round($quantity * $unitPrice, 2),
            'date' => $request->input('date'),
            'customer_id' => $request->input('customer_id'),
            'customer_name' => $request->input('customer_name'),
            'customer_phone' => $request->input('customer_phone'),
            'payment_method' => $request->input('payment_method'),
            'payment_status' => $request->input('payment_status', 'paid'),
            'notes' => $request->input('notes'),
            'created_by' => $request->user()->id,
        ]);

        return $this->sendResponse($record->load(['flock:id,name,batch_number', 'customer:id,name']), 'Product sale created successfully', 201);
    }

    public function update(Request $request, $farmId, $recordId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('update sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update product sales');
        }

        $record = SalesRecord::where('farm_id', $farmId)->findOrFail($recordId);

        $validator = Validator::make($request->all(), [
            'type' => 'sometimes|in:egg,meat,manure',
            'flock_id' => 'nullable|exists:flocks,id',
            'quantity' => 'sometimes|numeric|min:0.01',
            'unit_price' => 'sometimes|numeric|min:0',
            'date' => 'sometimes|date',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'payment_method' => 'nullable|string|max:50',
            'payment_status' => 'nullable|in:pending,paid,partial',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $type = $request->input('type', $record->type);
        $flockId = $request->has('flock_id') ? $request->input('flock_id') : $record->flock_id;
        $date = $request->input('date', $record->date?->toDateString());
        $quantity = round((float) $request->input('quantity', $record->quantity), 2);
        $unitPrice = round((float) $request->input('unit_price', $record->unit_price), 2);

        if (in_array($type, ['egg', 'meat'], true) && ! $flockId) {
            return $this->sendValidationError('Validation failed', [
                'flock_id' => ['Flock is required for egg and meat sales.'],
            ]);
        }

        if ($flockId) {
            $flock = Flock::where('farm_id', $farmId)->find($flockId);
            if (! $flock) {
                return $this->sendValidationError('Validation failed', [
                    'flock_id' => ['Flock does not belong to this farm.'],
                ]);
            }
        }

        if ($type === 'egg' && $flockId) {
            $check = $this->profitLossService->validateEggSaleQuantity(
                (int) $farmId,
                (int) $flockId,
                $date,
                $quantity,
                (int) $record->id
            );
            if (! $check['valid']) {
                return $this->sendError($check['message'], ['available' => $check['available'] ?? 0], 422);
            }
        }

        $record->fill([
            'type' => $type,
            'flock_id' => $flockId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => round($quantity * $unitPrice, 2),
            'date' => $date,
            'customer_id' => $request->has('customer_id') ? $request->input('customer_id') : $record->customer_id,
            'customer_name' => $request->has('customer_name') ? $request->input('customer_name') : $record->customer_name,
            'customer_phone' => $request->has('customer_phone') ? $request->input('customer_phone') : $record->customer_phone,
            'payment_method' => $request->has('payment_method') ? $request->input('payment_method') : $record->payment_method,
            'payment_status' => $request->input('payment_status', $record->payment_status),
            'notes' => $request->has('notes') ? $request->input('notes') : $record->notes,
        ]);
        $record->save();

        return $this->sendResponse($record->load(['flock:id,name,batch_number', 'customer:id,name']), 'Product sale updated successfully');
    }

    public function destroy(Request $request, $farmId, $recordId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('delete sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete product sales');
        }

        $record = SalesRecord::where('farm_id', $farmId)->findOrFail($recordId);
        $record->delete();

        return $this->sendResponse(null, 'Product sale deleted successfully');
    }

    private function canViewSales(Request $request, Farm $farm): bool
    {
        return $request->user()->hasPermissionTo('view sales', 'api', $farm)
            || $request->user()->hasPermissionTo('view flocks', 'api', $farm);
    }
}
