<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\FarmTaskTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class FarmTaskTemplateController extends ApiController
{
    public function index(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('view farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $query = FarmTaskTemplate::where('farm_id', $farm->id)->orderBy('title');
        if ($request->filled('section')) {
            $query->where('section', $request->section);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        return $this->sendResponse($query->get(), 'Task templates retrieved');
    }

    public function store(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $template = FarmTaskTemplate::create(array_merge(
            $validator->validated(),
            ['farm_id' => $farm->id, 'created_by' => $request->user()->id]
        ));

        return $this->sendResponse($template, 'Task template created', 201);
    }

    public function show(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('view farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $template = FarmTaskTemplate::where('farm_id', $farm->id)->findOrFail($id);

        return $this->sendResponse($template, 'Task template retrieved');
    }

    public function update(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $template = FarmTaskTemplate::where('farm_id', $farm->id)->findOrFail($id);
        $validator = Validator::make($request->all(), $this->rules(false));
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $template->update($validator->validated());

        return $this->sendResponse($template->fresh(), 'Task template updated');
    }

    public function destroy(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $template = FarmTaskTemplate::where('farm_id', $farm->id)->findOrFail($id);
        $template->delete();

        return $this->sendResponse(null, 'Task template deleted');
    }

    protected function rules(bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'title' => "{$req}|string|max:255",
            'description' => 'nullable|string',
            'section' => "{$req}|string|in:layers,broilers,turkeys,goats,pigs,medication,feeding,cleaning,general,mixed",
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'instructions' => 'nullable|string',
            'notes' => 'nullable|string',
            'animal_group' => 'nullable|string|max:255',
            'medication_name' => 'nullable|string|max:255',
            'dosage_instructions' => 'nullable|string',
            'require_completion_confirmation' => 'nullable|boolean',
            'require_supervisor_approval' => 'nullable|boolean',
            'require_signature' => 'nullable|boolean',
        ];
    }
}
