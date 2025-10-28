<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\MedicationProduct;
use App\Models\PoultryMedication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MedicationProductController extends ApiController
{
    /**
     * Display a listing of medication products.
     */
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!$user->hasPermissionTo('view medication products', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view medication products');
        }

        $query = MedicationProduct::where(function($q) use ($farm) {
            $q->where('farm_id', $farm->id)
              ->orWhereNull('farm_id');
        });

        if ($request->has('medication_id')) {
            $query->where('poultry_medication_id', $request->medication_id);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('manufacturer')) {
            $query->where('manufacturer', 'like', '%' . $request->manufacturer . '%');
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('manufacturer', 'like', "%{$search}%");
            });
        }
        $sortField = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortField, $sortDirection);
        $query->with(['farm']);
        $perPage = $request->input('per_page', 10);
        $products = $query->paginate($perPage);
        return $this->sendResponse($products, 'Medication products retrieved successfully');
    }

    /**
     * Store a newly created medication product.
     */
    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create medication products', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create medication products');
        }
        $validator = Validator::make($request->all(), [
            'poultry_medication_id' => 'required|exists:poultry_medications,id',
            'name' => 'required|string|max:255|unique:medication_products,name',
            'manufacturer' => 'required|string|max:255',
            'administration_method_id' => 'required|exists:administration_methods,id',
            'withdrawal_period' => 'nullable|integer|min:0',
            'withdrawal_period_unit' => 'nullable|string|in:days,hours',
            'dosage' => 'nullable|numeric|min:0',
            'dosage_unit' => 'nullable|string|max:50',
            'image_url' => 'nullable|url',
            'min_stock_level' => 'nullable|integer|min:0',
            
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $medication = PoultryMedication::findOrFail($request->poultry_medication_id);
        if ($medication->farm_id !== null && $medication->farm_id !== $farm->id) {
            return $this->sendError('Medication not found in this farm', [], 404);
        }
        $product = MedicationProduct::create(array_merge($request->all(), [
            'farm_id' => $farm->id,
            'type' => 'user'
        ]));
        $product->load(['farm']);
        return $this->sendResponse($product, 'Medication product created successfully', 201);
    }

    /**
     * Display the specified medication product.
     */
    public function show(Request $request, $farm, MedicationProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view medication products', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view medication products');
        }
        if ($product->farm_id !== null && $product->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Medication product not found in this farm');
        }
        $product->load(['farm']);
        return $this->sendResponse($product, 'Medication product retrieved successfully');
    }

    /**
     * Update the specified medication product.
     */
    public function update(Request $request, $farm, MedicationProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update medication products', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update medication products');
        }
        if ($product->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Medication product not found in this farm');
        }
        if ($product->type === 'default' && $product->farm_id === null) {
            return $this->sendError('Cannot update default medication products', [], 403);
        }
        $validator = Validator::make($request->all(), [
            'poultry_medication_id' => 'sometimes|required|exists:poultry_medications,id',
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('medication_products')->ignore($product->id)],
            'manufacturer' => 'sometimes|required|string|max:255',
            'administration_method_id' => 'sometimes|required|exists:administration_methods,id',
            'withdrawal_period' => 'nullable|integer|min:0',
            'withdrawal_period_unit' => 'nullable|string|in:days,hours',
            'dosage' => 'nullable|numeric|min:0',
            'dosage_unit' => 'nullable|string|max:50',
            'image_url' => 'nullable|url',
            'min_stock_level' => 'nullable|integer|min:0',
            'type' => ['sometimes', 'required', Rule::in(['default', 'user'])],
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $product->update($request->all());
        $product->load(['farm']);
        return $this->sendResponse($product, 'Medication product updated successfully');
    }

    /**
     * Remove the specified medication product.
     */
    public function destroy(Request $request, $farm, MedicationProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete medication products', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete medication products');
        }
        if ($product->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Medication product not found in this farm');
        }
        if ($product->type === 'default' && $product->farm_id === null) {
            return $this->sendError('Cannot delete default medication products', [], 403);
        }
        $product->delete();
        return $this->sendResponse(null, 'Medication product deleted successfully');
    }

    /**
     * Get medication product statistics.
     */
    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view medication products', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view medication products');
        }
        $query = MedicationProduct::where(function($q) use ($farm) {
            $q->where('farm_id', $farm->id)
              ->orWhereNull('farm_id');
        });
        $statistics = [
            'total_products' => $query->count(),
            'by_type' => $query->selectRaw('type, count(*) as count')
                             ->groupBy('type')
                             ->get(),
            'by_manufacturer' => $query->selectRaw('manufacturer, count(*) as count')
                                    ->groupBy('manufacturer')
                                    ->orderBy('count', 'desc')
                                    ->limit(10)
                                    ->get(),
            'by_medication' => $query->selectRaw('poultry_medication_id, count(*) as count')
                                ->groupBy('poultry_medication_id')
                                ->with('medication:id,name')
                                ->get(),
        ];
        return $this->sendResponse($statistics, 'Medication product statistics retrieved successfully');
    }
}