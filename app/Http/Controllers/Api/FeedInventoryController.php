<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\User;
use App\Services\FeedUsageInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FeedInventoryController extends ApiController
{
    /**
     * Farm roles use singular "view/manage feed inventory"; some seeders also
     * define plural CRUD names. Accept either so stocked inventory is visible.
     */
    protected function canViewFeedInventory(User $user, Farm $farm): bool
    {
        return $user->hasPermissionTo('view feed inventories', 'api', $farm)
            || $user->hasPermissionTo('view feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm);
    }

    protected function canManageFeedInventory(User $user, Farm $farm): bool
    {
        return $user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('create feed inventories', 'api', $farm)
            || $user->hasPermissionTo('update feed inventories', 'api', $farm)
            || $user->hasPermissionTo('delete feed inventories', 'api', $farm);
    }

    protected function canCreateFeedInventory(User $user, Farm $farm): bool
    {
        return $user->hasPermissionTo('create feed inventories', 'api', $farm)
            || $this->canManageFeedInventory($user, $farm);
    }

    protected function canUpdateFeedInventory(User $user, Farm $farm): bool
    {
        return $user->hasPermissionTo('update feed inventories', 'api', $farm)
            || $this->canManageFeedInventory($user, $farm);
    }

    protected function canDeleteFeedInventory(User $user, Farm $farm): bool
    {
        return $user->hasPermissionTo('delete feed inventories', 'api', $farm)
            || $this->canManageFeedInventory($user, $farm);
    }

    public function index(Request $request, $farm ,$pagination = null)
    {  
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$this->canViewFeedInventory($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed inventories');
        }
        // eager-load feed type (as poultry_feed_type alias) and creator
        $query = PoultryFeedInventory::with([
            'feedType',
            'createdby',
            'allocatedFlock',
        ])
            ->withCount('feedUsages')
            ->withMax('feedUsages as last_usage_date', 'usage_date')
            ->where('farm_id', $farm->id);
       
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('batch_number', 'like', "%{$search}%");
        }

        $sortField = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        // Always ensure newest items appear first by default.
        // If the client specifies a sort field, apply it first, then use created_at desc as a tiebreaker.
        if ($request->has('sort_by')) {
            $query->orderBy($sortField, $sortDirection);
            if ($sortField !== 'created_at') {
                $query->orderBy('created_at', 'desc');
            }
        } else {
            $query->orderBy('created_at', 'desc');
        }

        if ($pagination) {
            $perPage = $request->input('per_page', 10);
            $inventories = $query->paginate($perPage);
        } else {
            $inventories = $query->get();
        }

        $collection = $pagination ? $inventories->getCollection() : $inventories;
        $collection->each(function (PoultryFeedInventory $inventory) {
            $inventory->setAttribute(
                'can_delete',
                FeedUsageInventoryService::canDeleteInventory($inventory)
            );
        });
        
        return $this->sendResponse($inventories, 'Feed inventories retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$this->canCreateFeedInventory($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create feed inventories');
        }
        $validator = Validator::make($request->all(), [
            'poultry_feed_type_id' => 'required|exists:poultry_feed_types,id',
            'quantity' => 'required|numeric|min:0',
            'batch_number' => 'nullable|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:manufacture_date',
            'unit_cost' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $incomingQuantity = (float) $request->quantity;

        $inventory = DB::transaction(function () use ($request, $farm, $user, $incomingQuantity) {
            $inventory = PoultryFeedInventory::create(array_merge($request->all(), [
                'farm_id' => $farm->id,
                'created_by' => $user->id,
                'available_quantity' => $incomingQuantity,
                'status' => 'available',
            ]));

            $remainingQuantity = FeedUsageInventoryService::settleNegativeInventoriesFromNewStock($inventory);

            if ($remainingQuantity !== $incomingQuantity) {
                $inventory->update(['quantity' => $remainingQuantity]);
                $inventory->refresh();
                $inventory->updateStatusBasedOnQuantity();
            }

            return $inventory->fresh();
        });

        $inventory->setAttribute('can_delete', FeedUsageInventoryService::canDeleteInventory($inventory));
        return $this->sendResponse($inventory->load('feedType', 'createdby'), 'Feed inventory created successfully', 201);
    }

    public function show(Request $request, $farm, PoultryFeedInventory $inventory)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$this->canViewFeedInventory($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed inventories');
        }
        if ($inventory->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed inventory not found in this farm');
        }
        // ensure feed type and creator are loaded
        $inventory->load('feedType', 'createdby');
        $inventory->setAttribute('can_delete', FeedUsageInventoryService::canDeleteInventory($inventory));
        return $this->sendResponse($inventory, 'Feed inventory retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryFeedInventory $inventory)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$this->canUpdateFeedInventory($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update feed inventories');
        }
        if ($inventory->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed inventory not found in this farm');
        }
        $validator = Validator::make($request->all(), [
            'poultry_feed_type_id' => 'sometimes|required|exists:poultry_feed_types,id',
            'quantity' => 'sometimes|numeric|min:0',
            'batch_number' => 'nullable|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:manufacture_date',
            'unit_cost' => 'sometimes|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $payload = $request->only([
            'poultry_feed_type_id',
            'quantity',
            'batch_number',
            'manufacturer',
            'manufacture_date',
            'expiry_date',
            'unit_cost',
        ]);

        $inventory->update($payload);
        // reload relations
        $inventory->load('feedType', 'createdby');
        return $this->sendResponse($inventory, 'Feed inventory updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFeedInventory $inventory)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$this->canDeleteFeedInventory($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete feed inventories');
        }
        if ($inventory->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed inventory not found in this farm');
        }

        if (!FeedUsageInventoryService::canDeleteInventory($inventory)) {
            if ($inventory->feedUsages()->exists()) {
                return $this->sendError(
                    'Cannot delete inventory that has feed usage records. Only unused batches or newly created batches with auto-settlement can be removed.',
                    [],
                    422
                );
            }

            return $this->sendError('Cannot delete closed inventory', [], 422);
        }

        DB::transaction(function () use ($inventory) {
            if ($inventory->feedUsages()->exists()) {
                FeedUsageInventoryService::reverseSettlementsAndDelete($inventory);
            } else {
                $inventory->delete();
            }
        });

        return $this->sendResponse(null, 'Feed inventory deleted successfully');
    }

    public function transfer(Request $request, $farm, PoultryFeedInventory $inventory)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (! $this->canUpdateFeedInventory($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update feed inventories');
        }
        if ($inventory->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed inventory not found in this farm');
        }

        $validator = Validator::make($request->all(), [
            'from_inventory_id' => [
                'required',
                'integer',
                Rule::exists('poultry_feed_inventories', 'id')->where(fn ($q) => $q->where('farm_id', $farm->id)),
            ],
            'quantity' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $source = PoultryFeedInventory::where('farm_id', $farm->id)
            ->findOrFail((int) $request->from_inventory_id);

        try {
            $transferred = DB::transaction(function () use ($inventory, $source, $request) {
                return FeedUsageInventoryService::transferBetweenInventories(
                    $inventory,
                    $source,
                    (float) $request->quantity
                );
            });
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        $inventory->refresh()->load('feedType', 'createdby');
        $source->refresh()->load('feedType', 'createdby');

        return $this->sendResponse([
            'transferred_quantity' => $transferred,
            'target' => $inventory,
            'source' => $source,
        ], 'Feed inventory transfer completed successfully');
    }

    public function close(Request $request, $farm, PoultryFeedInventory $inventory)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$this->canUpdateFeedInventory($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update feed inventories');
        }
        if ($inventory->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed inventory not found in this farm');
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:1000',
            'flock_id' => 'nullable|integer|exists:flocks,id',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flockId = $request->input('flock_id');
        if ($flockId !== null) {
            $flock = Flock::where('id', $flockId)->where('farm_id', $farm->id)->first();
            if (!$flock) {
                return $this->sendValidationError('Validation failed', [
                    'flock_id' => ['Selected flock does not belong to this farm.'],
                ]);
            }
        }

        try {
            $inventory = FeedUsageInventoryService::closeInventory(
                $inventory,
                $user->id,
                $request->input('notes'),
                $flockId !== null ? (int) $flockId : null
            );

            return $this->sendResponse(
                $inventory->load('feedType', 'createdby', 'closedBy', 'allocatedFlock'),
                'Feed inventory closed and remaining stock recorded as damaged'
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$this->canViewFeedInventory($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed inventories');
        }
        $query = PoultryFeedInventory::where('farm_id', $farm->id);
        $byFeedType = $query->selectRaw('poultry_feed_type_id, sum(quantity) as total_quantity')
            ->groupBy('poultry_feed_type_id')
            ->get()
            ->map(function ($row) {
                $row->feed_type = PoultryFeedType::find($row->poultry_feed_type_id);
                return $row;
            });

        $statistics = [
            'total_feed_inventories' => $query->count(),
            'total_quantity' => $query->sum('quantity'),
            'by_feed_type' => $byFeedType,
        ];
        return $this->sendResponse($statistics, 'Feed inventory statistics retrieved successfully');
    }
}
