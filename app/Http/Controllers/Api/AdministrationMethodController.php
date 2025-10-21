<?php

namespace App\Http\Controllers\Api;

use App\Models\AdministrationMethod;
use Illuminate\Http\Request;

class AdministrationMethodController extends ApiController
{
    /**
     * Display a listing of administration methods.
     */
    public function index()
    {
        $administrationMethods = AdministrationMethod::where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->sendResponse($administrationMethods, 'Administration methods retrieved successfully');
    }

    /**
     * Store a newly created administration method.
     */
    public function store(Request $request)
    {
        $validator = validator($request->all(), [
            'name' => 'required|string|max:255|unique:administration_methods',
            'description' => 'required|string',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $administrationMethod = AdministrationMethod::create([
            'name' => $request->name,
            'description' => $request->description,
            'is_active' => $request->is_active ?? true,
        ]);

        return $this->sendResponse($administrationMethod, 'Administration method created successfully');
    }

    /**
     * Display the specified administration method.
     */
    public function show($id)
    {
        $administrationMethod = AdministrationMethod::findOrFail($id);
        return $this->sendResponse($administrationMethod, 'Administration method retrieved successfully');
    }

    /**
     * Update the specified administration method.
     */
    public function update(Request $request, $id)
    {
        $validator = validator($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:administration_methods,name,' . $id,
            'description' => 'sometimes|required|string',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $administrationMethod = AdministrationMethod::findOrFail($id);
        $administrationMethod->update($request->only(['name', 'description', 'is_active']));

        return $this->sendResponse($administrationMethod, 'Administration method updated successfully');
    }

    /**
     * Remove the specified administration method.
     */
    public function destroy($id)
    {
        $administrationMethod = AdministrationMethod::findOrFail($id);
        $administrationMethod->delete();

        return $this->sendResponse([], 'Administration method deleted successfully');
    }
}
