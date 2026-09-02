<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockSale;
use App\Services\CustomerResolver;
use App\Services\FlockSaleCullingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FlockSaleController extends ApiController
{
    public function index(Request $request, $farmId, $flockId)
    {
        $farm = Farm::findOrFail($farmId);
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if (! $request->user()->hasPermissionTo('view flocks', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock sales');
        }

        $query = FlockSale::with(['customer:id,name,phone', 'flock:id,name,batch_number'])
            ->where('farm_id', $farmId)
            ->where('flock_id', $flockId);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        $sales = $query->orderBy('date', 'desc')->get();

        return $this->sendResponse($sales, 'Flock sales retrieved successfully');
    }

    public function store(Request $request, $farmId, $flockId)
    {
        $farm = Farm::findOrFail($farmId);
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if (! $request->user()->hasPermissionTo('update flocks', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create flock sales');
        }

        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
            'date' => 'required|date',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $customerFields = CustomerResolver::resolveForFarm(
            $farm,
            $request->input('customer_id'),
            $request->input('customer_name'),
            $request->input('customer_phone')
        );
        if ($customerFields === null) {
            return $this->sendValidationError('Validation failed', [
                'customer_id' => ['Customer does not belong to this farm.'],
            ]);
        }

        $quantity = (int) $request->quantity;
        if ($quantity > $flock->actual_quantity) {
            return $this->sendError(
                'Cannot sell more birds than the current live flock count',
                ['available_quantity' => $flock->actual_quantity, 'requested_quantity' => $quantity],
                422
            );
        }

        $unitPrice = round((float) $request->unit_price, 2);
        $totalAmount = round($quantity * $unitPrice, 2);

        try {
            $batchClosed = false;
            $sale = DB::transaction(function () use ($request, $farm, $flock, $quantity, $unitPrice, $totalAmount, &$batchClosed) {
                [$dailyRecordId, $cullsApplied] = FlockSaleCullingService::applySaleCulling(
                    $flock,
                    $request->date,
                    $quantity,
                    $request->user()->id
                );

                $sale = FlockSale::create([
                    'farm_id' => $farm->id,
                    'customer_id' => $customerFields['customer_id'],
                    'flock_id' => $flock->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalAmount,
                    'date' => $request->date,
                    'customer_name' => $customerFields['customer_name'],
                    'customer_phone' => $customerFields['customer_phone'],
                    'notes' => $request->notes,
                    'daily_record_id' => $dailyRecordId,
                    'culls_applied' => $cullsApplied,
                    'created_by' => $request->user()->id,
                ]);

                $batchClosed = FlockSaleCullingService::syncBatchStatusAfterSaleChange($flock, $request->date);

                return $sale;
            });

            $message = $batchClosed
                ? 'Flock sale recorded successfully. Batch ended — all birds have been sold.'
                : 'Flock sale recorded successfully';

            return $this->sendResponse($sale, $message, 201);
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    public function update(Request $request, $farmId, $flockId, $id)
    {
        $farm = Farm::findOrFail($farmId);
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if (! $request->user()->hasPermissionTo('update flocks', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update flock sales');
        }

        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $sale = FlockSale::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'quantity' => 'sometimes|required|integer|min:1',
            'unit_price' => 'sometimes|required|numeric|min:0',
            'date' => 'sometimes|required|date',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $customerFields = CustomerResolver::resolveForFarm(
            $farm,
            $request->has('customer_id') ? $request->input('customer_id') : $sale->customer_id,
            $request->has('customer_name') ? $request->input('customer_name') : $sale->customer_name,
            $request->has('customer_phone') ? $request->input('customer_phone') : $sale->customer_phone
        );
        if ($customerFields === null) {
            return $this->sendValidationError('Validation failed', [
                'customer_id' => ['Customer does not belong to this farm.'],
            ]);
        }

        $newQuantity = $request->has('quantity') ? (int) $request->quantity : (int) $sale->quantity;
        $newDate = $request->has('date') ? $request->date : $sale->date->toDateString();
        $newUnitPrice = $request->has('unit_price') ? round((float) $request->unit_price, 2) : (float) $sale->unit_price;

        $availableAfterReversal = $flock->actual_quantity + (int) $sale->culls_applied;
        if ($newQuantity > $availableAfterReversal) {
            return $this->sendError(
                'Cannot sell more birds than the current live flock count',
                ['available_quantity' => $availableAfterReversal, 'requested_quantity' => $newQuantity],
                422
            );
        }

        try {
            $batchClosed = false;
            $sale = DB::transaction(function () use ($request, $flock, $sale, $newQuantity, $newDate, $newUnitPrice, &$batchClosed) {
                [$dailyRecordId, $cullsApplied] = FlockSaleCullingService::replaceSaleCulling(
                    $sale,
                    $flock,
                    $newDate,
                    $newQuantity,
                    $request->user()->id
                );

                $sale->update([
                    'quantity' => $newQuantity,
                    'unit_price' => $newUnitPrice,
                    'total_amount' => round($newQuantity * $newUnitPrice, 2),
                    'date' => $newDate,
                    'customer_id' => $customerFields['customer_id'],
                    'customer_name' => $customerFields['customer_name'],
                    'customer_phone' => $customerFields['customer_phone'],
                    'notes' => $request->has('notes') ? $request->notes : $sale->notes,
                    'daily_record_id' => $dailyRecordId,
                    'culls_applied' => $cullsApplied,
                    'updated_by' => $request->user()->id,
                ]);

                $batchClosed = FlockSaleCullingService::syncBatchStatusAfterSaleChange($flock, $newDate);

                return $sale->fresh();
            });

            $message = $batchClosed
                ? 'Flock sale updated successfully. Batch ended — all birds have been sold.'
                : 'Flock sale updated successfully';

            return $this->sendResponse($sale, $message);
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    public function destroy(Request $request, $farmId, $flockId, $id)
    {
        $farm = Farm::findOrFail($farmId);
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if (! $request->user()->hasPermissionTo('update flocks', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete flock sales');
        }

        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $sale = FlockSale::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->findOrFail($id);

        DB::transaction(function () use ($sale, $flock) {
            FlockSaleCullingService::reverseSaleCulling($sale);
            $sale->delete();
            FlockSaleCullingService::syncBatchStatusAfterSaleChange($flock);
        });

        return $this->sendResponse(null, 'Flock sale deleted successfully');
    }
}
