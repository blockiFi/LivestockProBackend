<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdministrationMethod;
use App\Models\Country;
use App\Models\FlockStage;
use App\Models\Group;
use App\Models\LiterType;
use App\Models\Permission;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReferenceDataController extends ApiController
{
    use LogsAdminAction;

    public function administrationMethods(): JsonResponse
    {
        return $this->sendResponse(AdministrationMethod::orderBy('name')->get(), 'Administration methods retrieved');
    }

    public function storeAdministrationMethod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:administration_methods,name',
            'description' => 'nullable|string',
        ]);

        $method = AdministrationMethod::create($validated);
        $this->logAdminAction($request, 'reference.administration_method.create', 'administration_method', $method->id);

        return $this->sendResponse($method, 'Administration method created', 201);
    }

    public function updateAdministrationMethod(Request $request, AdministrationMethod $administrationMethod): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:administration_methods,name,' . $administrationMethod->id,
            'description' => 'nullable|string',
        ]);

        $administrationMethod->update($validated);
        $this->logAdminAction($request, 'reference.administration_method.update', 'administration_method', $administrationMethod->id);

        return $this->sendResponse($administrationMethod, 'Administration method updated');
    }

    public function destroyAdministrationMethod(Request $request, AdministrationMethod $administrationMethod): JsonResponse
    {
        $administrationMethod->delete();
        $this->logAdminAction($request, 'reference.administration_method.delete', 'administration_method', $administrationMethod->id);

        return $this->sendResponse(null, 'Administration method deleted');
    }

    public function literTypes(): JsonResponse
    {
        return $this->sendResponse(LiterType::orderBy('name')->get(), 'Liter types retrieved');
    }

    public function storeLiterType(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:255|unique:liter_types,name']);
        $type = LiterType::create($validated);
        $this->logAdminAction($request, 'reference.liter_type.create', 'liter_type', $type->id);

        return $this->sendResponse($type, 'Liter type created', 201);
    }

    public function updateLiterType(Request $request, LiterType $literType): JsonResponse
    {
        $validated = $request->validate(['name' => 'sometimes|string|max:255|unique:liter_types,name,' . $literType->id]);
        $literType->update($validated);
        $this->logAdminAction($request, 'reference.liter_type.update', 'liter_type', $literType->id);

        return $this->sendResponse($literType, 'Liter type updated');
    }

    public function destroyLiterType(Request $request, LiterType $literType): JsonResponse
    {
        $literType->delete();
        $this->logAdminAction($request, 'reference.liter_type.delete', 'liter_type', $literType->id);

        return $this->sendResponse(null, 'Liter type deleted');
    }

    public function flockStages(): JsonResponse
    {
        return $this->sendResponse(FlockStage::with('poultryType:id,name')->orderBy('name')->get(), 'Flock stages retrieved');
    }

    public function countries(): JsonResponse
    {
        return $this->sendResponse(Country::orderBy('name')->get(['id', 'name', 'iso_code']), 'Countries retrieved');
    }

    public function permissionGroups(): JsonResponse
    {
        $groups = Group::withCount('permissions')->orderBy('name')->get();

        return $this->sendResponse($groups, 'Permission groups retrieved');
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::with('group:id,name,color')->orderBy('name')->get();

        return $this->sendResponse($permissions, 'Permissions retrieved');
    }
}
