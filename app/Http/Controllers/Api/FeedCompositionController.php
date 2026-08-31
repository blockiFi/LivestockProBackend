<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\FeedComposition;
use App\Models\FeedComponent;
use App\Models\PoultryFeedProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedCompositionController extends ApiController
{
    private function ensureProductAccessible(Farm $farm, PoultryFeedProduct $product, bool $allowGlobal = false)
    {
        if ($product->farm_id !== $farm->id && !($allowGlobal && $product->farm_id === null)) {
            return $this->sendNotFoundError('Feed product not found in this farm');
        }
        return null;
    }

    public function index(Request $request, $farm, PoultryFeedProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('view feed products', 'api', $farm)
            || $user->hasPermissionTo('view feed inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to view feed compositions');
        }

        if ($resp = $this->ensureProductAccessible($farm, $product, true)) {
            return $resp;
        }

        $items = FeedComposition::with('component')
            ->where('poultry_feed_product_id', $product->id)
            ->orderBy('id', 'asc')
            ->get();

        return $this->sendResponse($items, 'Feed compositions retrieved successfully');
    }

    public function store(Request $request, $farm, PoultryFeedProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('update feed products', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to manage feed compositions');
        }

        if ($resp = $this->ensureProductAccessible($farm, $product)) {
            return $resp;
        }

        $validator = Validator::make($request->all(), [
            'feed_component_id' => 'required|exists:feed_components,id',
            'percentage' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        // Ensure component belongs to this farm or is global
        $component = FeedComponent::findOrFail($request->input('feed_component_id'));
        if ($component->farm_id !== null && $component->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed component not found in this farm');
        }

        $item = FeedComposition::create([
            'poultry_feed_product_id' => $product->id,
            'feed_component_id' => $component->id,
            'percentage' => $request->input('percentage'),
        ]);

        $item->load('component');
        return $this->sendResponse($item, 'Feed composition item created successfully', 201);
    }

    public function update(Request $request, $farm, PoultryFeedProduct $product, FeedComposition $composition)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('update feed products', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to manage feed compositions');
        }

        if ($resp = $this->ensureProductAccessible($farm, $product)) {
            return $resp;
        }

        if ($composition->poultry_feed_product_id !== $product->id) {
            return $this->sendNotFoundError('Feed composition item not found for this product');
        }

        $validator = Validator::make($request->all(), [
            'percentage' => 'required|numeric|min:0|max:100',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $composition->update(['percentage' => $request->input('percentage')]);
        $composition->load('component');
        return $this->sendResponse($composition, 'Feed composition item updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFeedProduct $product, FeedComposition $composition)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('delete feed products', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to manage feed compositions');
        }

        if ($resp = $this->ensureProductAccessible($farm, $product)) {
            return $resp;
        }

        if ($composition->poultry_feed_product_id !== $product->id) {
            return $this->sendNotFoundError('Feed composition item not found for this product');
        }

        $composition->delete();
        return $this->sendResponse(null, 'Feed composition item deleted successfully');
    }

    public function calculateNutrition(Request $request, $farm, PoultryFeedProduct $product)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('update feed products', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to calculate feed nutrition');
        }

        if ($resp = $this->ensureProductAccessible($farm, $product)) {
            return $resp;
        }

        $items = FeedComposition::with('component')
            ->where('poultry_feed_product_id', $product->id)
            ->get();

        $nutrients = [
            'crude_protein' => 0.0,
            'crude_fat' => 0.0,
            'crude_fiber' => 0.0,
            'calcium' => 0.0,
            'phosphorus' => 0.0,
            'metabolizable_energy' => 0.0,
            'moisture' => 0.0,
            'ash' => 0.0,
        ];

        foreach ($items as $item) {
            $pct = (float) $item->percentage;
            $c = $item->component;
            if (!$c) continue;
            foreach ($nutrients as $key => $val) {
                $n = (float) ($c->{$key} ?? 0);
                $nutrients[$key] += ($n * $pct / 100.0);
            }
        }

        // round + persist to product
        $updates = [];
        foreach ($nutrients as $key => $val) {
            $updates[$key] = round($val, 2);
        }
        $product->update($updates);
        $product->refresh();

        return $this->sendResponse([
            'nutrients' => $updates,
            'product' => $product,
        ], 'Feed nutrition calculated successfully');
    }
}

