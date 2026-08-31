<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ChecksEquipmentAccess;
use App\Models\EquipmentCategory;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EquipmentCategoryController extends ApiController
{
    use ChecksEquipmentAccess;

    public function index(Request $request, $farm)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canViewEquipment($farm)) {
            return $this->denyView();
        }

        $query = EquipmentCategory::query()
            ->forFarm($farm->id)
            ->orderBy('sort_order')
            ->orderBy('name');

        if (!$request->boolean('include_inactive')) {
            $query->active();
        }

        return $this->sendResponse($query->get(), 'Equipment categories retrieved');
    }

    public function store(Request $request, $farm)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $slug = Str::slug($request->name);
        $exists = EquipmentCategory::where('farm_id', $farm->id)->where('slug', $slug)->exists();
        if ($exists) {
            return $this->sendValidationError('Validation failed', ['name' => ['Category already exists']]);
        }

        $category = EquipmentCategory::create([
            'farm_id' => $farm->id,
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
            'sort_order' => $request->input('sort_order', 0),
            'is_active' => true,
        ]);

        return $this->sendResponse($category, 'Category created', 201);
    }

    public function update(Request $request, $farm, EquipmentCategory $category)
    {
        $farm = $this->farmContext($farm);
        if (!$this->canManageEquipment($farm)) {
            return $this->denyManage();
        }

        if ($category->farm_id !== null && (int) $category->farm_id !== (int) $farm->id) {
            return $this->sendNotFoundError('Category not found');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:120',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        if ($request->filled('name') && $category->farm_id !== null) {
            $category->name = $request->name;
            $category->slug = Str::slug($request->name);
        }

        if ($request->has('description')) {
            $category->description = $request->description;
        }
        if ($request->has('sort_order')) {
            $category->sort_order = (int) $request->sort_order;
        }
        if ($request->has('is_active')) {
            $category->is_active = $request->boolean('is_active');
        }

        $category->save();

        return $this->sendResponse($category, 'Category updated');
    }
}
