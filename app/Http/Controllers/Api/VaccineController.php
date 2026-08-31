<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PermissionDoesNotExist;
use App\Models\Farm;
use App\Models\PoultryVaccine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class VaccineController extends ApiController
{
    private function canAny($user, Farm $farm, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            try {
                if ($user->hasPermissionTo($permission, 'api', $farm)) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                continue;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function viewVaccinePermissions(): array
    {
        return [
            'view vaccines',
            'create vaccines',
            'update vaccines',
            'delete vaccines',
            'view vaccine products',
            'create vaccine products',
            'update vaccine products',
            'view vaccine inventory',
            'view inventory',
            'manage vaccine inventory',
            'manage inventory',
        ];
    }

    /** @return array<int, string> */
    private function createVaccinePermissions(): array
    {
        return [
            'create vaccines',
            'create vaccine products',
            'manage vaccine inventory',
            'manage inventory',
        ];
    }

    /**
     * Display a listing of the vaccines.
     */
    public function index(Request $request, $farm , $paginated = null)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (! $this->canAny($user, $farm, $this->viewVaccinePermissions())) {
            return $this->sendUnauthorizedError('Unauthorized to view vaccines');
        }

        // Apply filters — include platform defaults and farm-specific types
        $query = PoultryVaccine::with('products')
            ->where(function ($q) use ($farm) {
                $q->where('farm_id', $farm->id)->orWhereNull('farm_id');
            });

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Apply sorting
        $sortField = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        // Paginate results
        if ($paginated) {
            $perPage = $request->input('per_page', 10);
            $vaccines = $query->paginate($perPage);
        } else {
            $vaccines = $query->get();
        }

        return $this->sendResponse($vaccines, 'Vaccines retrieved successfully');
    }
    
    /**
     * Display a listing of the vaccines with products and inventories.
     */
    public function data(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (! $this->canAny($user, $farm, $this->viewVaccinePermissions())) {
            return $this->sendUnauthorizedError('Unauthorized to view vaccines');
        }

        // Apply filters
        $query = PoultryVaccine::with([
            'products' => function ($q) use ($farm) {
                $q->where(function ($inner) use ($farm) {
                    $inner->where('farm_id', $farm->id)->orWhereNull('farm_id');
                })->with([
                    'administrationMethod',
                    'inventories' => fn ($iq) => $iq->where('farm_id', $farm->id),
                ]);
            },
        ])
            ->where(function($q) use ($farm) {
                $q->where('farm_id', $farm->id)
                  ->orWhereNull('farm_id');
            });

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Apply sorting
        $sortField = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        $vaccines = $query->get();

        return $this->sendResponse($vaccines, 'Vaccines retrieved successfully');
    }
    
    /**
     * Store a newly created vaccine.
     */
    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (! $this->canAny($user, $farm, $this->createVaccinePermissions())) {
            return $this->sendUnauthorizedError('Unauthorized to create vaccines');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'administration_age' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $vaccine = $farm->vaccines()->create($request->all() + [
            'type' => 'user',
        ]);

        return $this->sendResponse($vaccine, 'Vaccine created successfully', 201);
    }

    /**
     * Display the specified vaccine.
     */
    public function show(Request $request, $farm, PoultryVaccine $vaccine)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        // Check if user has permission to view vaccines
        if (!$user->hasPermissionTo('view vaccines', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view vaccines');
        }

        // Verify vaccine belongs to farm
        

        return $this->sendResponse($vaccine, 'Vaccine retrieved successfully');
    }

    /**
     * Update the specified vaccine.
     */
    public function update(Request $request, $farm, PoultryVaccine $vaccine)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        // Check if user has permission to update vaccines
        if (!$user->hasPermissionTo('update vaccines', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update vaccines');
        }

        // Verify vaccine belongs to farm
        if ($vaccine->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Vaccine not found in this farm');
        }

        // Prevent updating default vaccines
        if ($vaccine->type === 'default' && $vaccine->farm_id === null) {
            return $this->sendError('Cannot update default vaccines', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'type' => ['sometimes', 'required', Rule::in(['default', 'custom'])],
            'administration_age' => 'sometimes|required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $vaccine->update($request->all());

        return $this->sendResponse($vaccine, 'Vaccine updated successfully');
    }

    /**
     * Remove the specified vaccine.
     */
    public function destroy(Request $request, $farm,  $vaccine)
    {
        $user = $request->user();
        $vaccine = PoultryVaccine::find($vaccine);
        if (!$vaccine) {
            return $this->sendNotFoundError('Vaccine not found');
        }
        $farm = Farm::findOrFail($farm);
       

        // Check if user has permission to delete vaccines
        if (!$user->hasPermissionTo('delete vaccines', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete vaccines');
        }

        // Verify vaccine belongs to farm
        if ($vaccine->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Vaccine not found in this farm');
        }

        // Prevent deleting default vaccines
        if ($vaccine->type === 'default' && $vaccine->farm_id === null) {
            return $this->sendError('Cannot delete default vaccines', [], 403);
        }

        $vaccine->delete();

        return $this->sendResponse(null, 'Vaccine deleted successfully');
    }

   
}
