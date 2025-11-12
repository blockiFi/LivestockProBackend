<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\PoultryFeedProduct;
use App\Models\PoultryFeedType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PoultryFeedProductController extends ApiController
{
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('view feed products', 'api', $farm)
            || $user->hasPermissionTo('view feed inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to view feed products');
        }

        $products = PoultryFeedProduct::all();

        

        return $this->sendResponse($products, 'Feed products retrieved successfully');
    }

    public function show(Request $request, $farm, PoultryFeedProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('view feed products', 'api', $farm)
            || $user->hasPermissionTo('view feed inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to view feed products');
        }

        if ($product->farm_id !== null && $product->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed product not found in this farm');
        }

        $product->load('feedType');
        return $this->sendResponse($product, 'Feed product retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('create feed products', 'api', $farm)
            || $user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to create feed products');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'poultry_feed_type_id' => 'nullable|exists:poultry_feed_types,id',
            'sku' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'type' => ['sometimes', Rule::in(['default', 'user'])],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $product = PoultryFeedProduct::create(array_merge($request->all(), [
            'farm_id' => $farm->id
        ]));

        $product->load('feedType');
        return $this->sendResponse($product, 'Feed product created successfully', 201);
    }

    public function update(Request $request, $farm, PoultryFeedProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('update feed products', 'api', $farm)
            || $user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to update feed products');
        }

        if ($product->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed product not found in this farm');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'poultry_feed_type_id' => 'nullable|exists:poultry_feed_types,id',
            'sku' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'type' => ['sometimes', Rule::in(['default', 'user'])],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $product->update($request->all());
        $product->load('feedType');
        return $this->sendResponse($product, 'Feed product updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFeedProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('delete feed products', 'api', $farm)
            || $user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to delete feed products');
        }

        if ($product->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed product not found in this farm');
        }

        $product->delete();
        return $this->sendResponse(null, 'Feed product deleted successfully');
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('view feed products', 'api', $farm)
            || $user->hasPermissionTo('view feed inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to view feed products');
        }

        $query = PoultryFeedProduct::where(function($q) use ($farm) {
            $q->where('farm_id', $farm->id)
              ->orWhereNull('farm_id');
        });

        $statistics = [
            'total_products' => $query->count(),
            'by_type' => $query->selectRaw('poultry_feed_type_id, count(*) as count')
                             ->groupBy('poultry_feed_type_id')
                             ->get(),
            'by_manufacturer' => $query->selectRaw('manufacturer, count(*) as count')
                                    ->groupBy('manufacturer')
                                    ->orderBy('count', 'desc')
                                    ->limit(10)
                                    ->get(),
        ];

        return $this->sendResponse($statistics, 'Feed product statistics retrieved successfully');
    }
}
