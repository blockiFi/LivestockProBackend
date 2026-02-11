<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\PoultryVaccine;
use App\Models\PoultryVaccineProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class VaccineProductController extends ApiController
{
    /**
     * Display a listing of vaccine products.
     */
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        // Check if user has permission to view vaccine products or broader inventory permissions
        if (!($user->hasPermissionTo('view vaccine products', 'api', $farm)
            || $user->hasPermissionTo('view vaccine inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm))) {
             return $this->sendUnauthorizedError('Unauthorized to view vaccine products');
         }

        // Build query for vaccine products
        $query = PoultryVaccineProduct::where(function($q) use ($farm) {
            $q->where('farm_id', $farm->id)
              ->orWhereNull('farm_id'); // Include default products
        });

        // Apply filters
        if ($request->has('poultry_vaccine_id')) {
            $query->where('poultry_vaccine_id', $request->poultry_vaccine_id);
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

        // Apply sorting
        $sortField = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        // Include relationships
        $query->with(['vaccine', 'administrationMethod']);

        // Check if pagination is requested (properly handle string "false")
        $paginated = filter_var($request->input('paginated', true), FILTER_VALIDATE_BOOLEAN);
        
        if ($paginated) {
            // Paginate results
            $perPage = $request->input('per_page', 10);
            $products = $query->paginate($perPage);
        } else {
            // Return all results without pagination
            $products = $query->get();
        }

        return $this->sendResponse($products, 'Vaccine products retrieved successfully');
    }

    /**
     * Store a newly created vaccine product.
     */
    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        // Check if user has permission to create vaccine products or broader inventory permissions
        if (!($user->hasPermissionTo('create vaccine products', 'api', $farm)
            || $user->hasPermissionTo('manage vaccine inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('create vaccines', 'api', $farm))) {
             return $this->sendUnauthorizedError('Unauthorized to create vaccine products');
         }

        $validator = Validator::make($request->all(), [
            'poultry_vaccine_id' => 'required|exists:poultry_vaccines,id',
            'name' => 'required|string|max:255|unique:poultry_vaccine_products,name',
            'manufacturer' => 'required|string|max:255',
            'administration_method_id' => 'required|exists:administration_methods,id',
            'withdrawal_period' => 'nullable|integer|min:0',
            'withdrawal_period_unit' => 'nullable|string|in:days,hours',
            'dosage' => 'nullable|numeric|min:0',
            'dosage_unit' => 'nullable|string|max:50',
            'image_url' => 'nullable|url',
            'type' => ['required', Rule::in(['default', 'user'])],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        // Verify vaccine belongs to farm or is default
        $vaccine = PoultryVaccine::findOrFail($request->poultry_vaccine_id);
        if ($vaccine->farm_id !== null && $vaccine->farm_id !== $farm->id) {
            return $this->sendError('Vaccine not found in this farm', [], 404);
        }

        $product = PoultryVaccineProduct::create(array_merge($request->all(), [
            'farm_id' => $farm->id
        ]));

        $product->load(['vaccine', 'administrationMethod']);

        return $this->sendResponse($product, 'Vaccine product created successfully', 201);
    }

    /**
     * Display the specified vaccine product.
     */
    public function show(Request $request, $farm, PoultryVaccineProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        // Check if user has permission to view vaccine products or broader inventory permissions
        if (!($user->hasPermissionTo('view vaccine products', 'api', $farm)
            || $user->hasPermissionTo('view vaccine inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm))) {
             return $this->sendUnauthorizedError('Unauthorized to view vaccine products');
         }

        // Verify product belongs to farm or is default
        if ($product->farm_id !== null && $product->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Vaccine product not found in this farm');
        }

        $product->load(['vaccine', 'administrationMethod']);

        return $this->sendResponse($product, 'Vaccine product retrieved successfully');
    }

    /**
     * Update the specified vaccine product.
     */
    public function update(Request $request, $farm, PoultryVaccineProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        // Check if user has permission to update vaccine products or broader inventory permissions
        if (!($user->hasPermissionTo('update vaccine products', 'api', $farm)
            || $user->hasPermissionTo('manage vaccine inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm))) {
             return $this->sendUnauthorizedError('Unauthorized to update vaccine products');
         }

        // Verify product belongs to farm
        if ($product->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Vaccine product not found in this farm');
        }

        // Prevent updating default products
        if ($product->type === 'default' && $product->farm_id === null) {
            return $this->sendError('Cannot update default vaccine products', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'poultry_vaccine_id' => 'sometimes|required|exists:poultry_vaccines,id',
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('poultry_vaccine_products')->ignore($product->id)],
            'manufacturer' => 'sometimes|required|string|max:255',
            'administration_method_id' => 'sometimes|required|exists:administration_methods,id',
            'withdrawal_period' => 'nullable|integer|min:0',
            'withdrawal_period_unit' => 'nullable|string|in:days,hours',
            'dosage' => 'nullable|numeric|min:0',
            'dosage_unit' => 'nullable|string|max:50',
            'image_url' => 'nullable|url',
            'type' => ['sometimes', 'required', Rule::in(['default', 'user'])],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $product->update($request->all());
        $product->load(['vaccine', 'administrationMethod']);

        return $this->sendResponse($product, 'Vaccine product updated successfully');
    }

    /**
     * Remove the specified vaccine product.
     */
    public function destroy(Request $request, $farm, PoultryVaccineProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        // Check if user has permission to delete vaccine products or broader inventory permissions
        if (!($user->hasPermissionTo('delete vaccine products', 'api', $farm)
            || $user->hasPermissionTo('manage vaccine inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm))) {
             return $this->sendUnauthorizedError('Unauthorized to delete vaccine products');
         }

        // Verify product belongs to farm
        if ($product->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Vaccine product not found in this farm');
        }

        // Prevent deleting default products
        if ($product->type === 'default' && $product->farm_id === null) {
            return $this->sendError('Cannot delete default vaccine products', [], 403);
        }

        $product->delete();

        return $this->sendResponse(null, 'Vaccine product deleted successfully');
    }

    /**
     * Get vaccine product statistics.
     */
    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        // Check if user has permission to view vaccine products or broader inventory permissions
        if (!($user->hasPermissionTo('view vaccine products', 'api', $farm)
            || $user->hasPermissionTo('view vaccine inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm))) {
             return $this->sendUnauthorizedError('Unauthorized to view vaccine products');
         }

        $query = PoultryVaccineProduct::where(function($q) use ($farm) {
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
            'by_vaccine' => $query->selectRaw('poultry_vaccine_id, count(*) as count')
                                ->groupBy('poultry_vaccine_id')
                                ->with('vaccine:id,name')
                                ->get(),
        ];

        return $this->sendResponse($statistics, 'Vaccine product statistics retrieved successfully');
    }
}
