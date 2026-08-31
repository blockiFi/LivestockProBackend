<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\FeedComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedComponentController extends ApiController
{
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('view feed products', 'api', $farm)
            || $user->hasPermissionTo('view feed inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to view feed components');
        }

        $query = FeedComponent::where(function ($q) use ($farm) {
            $q->where('farm_id', $farm->id)->orWhereNull('farm_id');
        });

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('status') && in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->input('status'));
        }

        $components = $query->orderBy('name')->get();
        return $this->sendResponse($components, 'Feed components retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('create feed products', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to create feed components');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'unit' => 'nullable|string|max:50',
            'crude_protein' => 'nullable|numeric|min:0|max:100',
            'crude_fat' => 'nullable|numeric|min:0|max:100',
            'crude_fiber' => 'nullable|numeric|min:0|max:100',
            'calcium' => 'nullable|numeric|min:0|max:100',
            'phosphorus' => 'nullable|numeric|min:0|max:100',
            'metabolizable_energy' => 'nullable|numeric|min:0',
            'moisture' => 'nullable|numeric|min:0|max:100',
            'ash' => 'nullable|numeric|min:0|max:100',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $component = FeedComponent::create(array_merge($request->all(), [
            'farm_id' => $farm->id,
            'created_by' => $user?->id,
        ]));

        return $this->sendResponse($component, 'Feed component created successfully', 201);
    }

    public function show(Request $request, $farm, FeedComponent $component)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('view feed products', 'api', $farm)
            || $user->hasPermissionTo('view feed inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to view feed components');
        }

        if ($component->farm_id !== null && $component->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed component not found in this farm');
        }

        return $this->sendResponse($component, 'Feed component retrieved successfully');
    }

    public function update(Request $request, $farm, FeedComponent $component)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('update feed products', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to update feed components');
        }

        if ($component->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed component not found in this farm');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'unit' => 'nullable|string|max:50',
            'crude_protein' => 'nullable|numeric|min:0|max:100',
            'crude_fat' => 'nullable|numeric|min:0|max:100',
            'crude_fiber' => 'nullable|numeric|min:0|max:100',
            'calcium' => 'nullable|numeric|min:0|max:100',
            'phosphorus' => 'nullable|numeric|min:0|max:100',
            'metabolizable_energy' => 'nullable|numeric|min:0',
            'moisture' => 'nullable|numeric|min:0|max:100',
            'ash' => 'nullable|numeric|min:0|max:100',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $component->update($request->all());
        return $this->sendResponse($component, 'Feed component updated successfully');
    }

    public function destroy(Request $request, $farm, FeedComponent $component)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('delete feed products', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to delete feed components');
        }

        if ($component->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed component not found in this farm');
        }

        $component->delete();
        return $this->sendResponse(null, 'Feed component deleted successfully');
    }

    public function generateWithAI(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!($user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('create feed products', 'api', $farm))) {
            return $this->sendUnauthorizedError('Unauthorized to generate feed components');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $componentName = $request->input('name');
        
        // Use AI to generate component data
        $llmService = app(\App\Services\LlmService::class);
        
        $systemPrompt = 'You are an expert poultry nutritionist. Given a feed component name, provide its typical nutritional values in JSON format.';
        $userPrompt = "Provide nutritional data for the feed component: {$componentName}\n\n" .
            "Return ONLY a valid JSON object with these fields:\n" .
            "{\n" .
            "  \"name\": \"exact component name\",\n" .
            "  \"description\": \"brief description of the component\",\n" .
            "  \"unit\": \"kg\",\n" .
            "  \"crude_protein\": number (0-100),\n" .
            "  \"crude_fat\": number (0-100),\n" .
            "  \"crude_fiber\": number (0-100),\n" .
            "  \"calcium\": number (0-100),\n" .
            "  \"phosphorus\": number (0-100),\n" .
            "  \"metabolizable_energy\": number (kcal/kg, typically 0-4000),\n" .
            "  \"moisture\": number (0-100),\n" .
            "  \"ash\": number (0-100)\n" .
            "}\n\n" .
            "Use realistic values based on standard poultry feed ingredient databases. If unsure about a value, use a reasonable estimate.";

        $aiResponse = $llmService->chat($systemPrompt, $userPrompt);
        
        if (!$aiResponse) {
            return $this->sendError('Failed to generate component data using AI. Please check AI configuration.', 500);
        }

        // Try to extract JSON from the response
        $jsonData = null;
        
        // Look for JSON in the response (might be wrapped in markdown code blocks)
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $aiResponse, $matches)) {
            $jsonData = json_decode($matches[1], true);
        } elseif (preg_match('/(\{.*\})/s', $aiResponse, $matches)) {
            $jsonData = json_decode($matches[1], true);
        } else {
            // Try parsing the whole response as JSON
            $jsonData = json_decode($aiResponse, true);
        }

        if (!$jsonData || !is_array($jsonData)) {
            return $this->sendError('AI response could not be parsed as valid JSON. Response: ' . substr($aiResponse, 0, 200), 500);
        }

        // Ensure name matches
        $jsonData['name'] = $componentName;
        
        // Validate and sanitize the data
        $componentData = [
            'name' => $jsonData['name'] ?? $componentName,
            'description' => $jsonData['description'] ?? "AI-generated feed component: {$componentName}",
            'unit' => $jsonData['unit'] ?? 'kg',
            'crude_protein' => isset($jsonData['crude_protein']) ? max(0, min(100, (float)$jsonData['crude_protein'])) : null,
            'crude_fat' => isset($jsonData['crude_fat']) ? max(0, min(100, (float)$jsonData['crude_fat'])) : null,
            'crude_fiber' => isset($jsonData['crude_fiber']) ? max(0, min(100, (float)$jsonData['crude_fiber'])) : null,
            'calcium' => isset($jsonData['calcium']) ? max(0, min(100, (float)$jsonData['calcium'])) : null,
            'phosphorus' => isset($jsonData['phosphorus']) ? max(0, min(100, (float)$jsonData['phosphorus'])) : null,
            'metabolizable_energy' => isset($jsonData['metabolizable_energy']) ? max(0, (float)$jsonData['metabolizable_energy']) : null,
            'moisture' => isset($jsonData['moisture']) ? max(0, min(100, (float)$jsonData['moisture'])) : null,
            'ash' => isset($jsonData['ash']) ? max(0, min(100, (float)$jsonData['ash'])) : null,
            'status' => 'active',
            'farm_id' => $farm->id,
            'created_by' => $user?->id,
        ];

        $component = FeedComponent::create($componentData);
        
        return $this->sendResponse($component, 'Feed component generated successfully using AI', 201);
    }
}

