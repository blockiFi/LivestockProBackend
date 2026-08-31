<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\FeedComponent;
use App\Models\PoultryFeedProduct;
use App\Services\FeedFormulaAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedFormulaAnalysisController extends ApiController
{
    public function __construct(protected FeedFormulaAnalysisService $service)
    {
    }

    protected function ensureCanView(Request $request, Farm $farm): bool
    {
        $user = $request->user();
        return $user->hasPermissionTo('view feed products', 'api', $farm)
            || $user->hasPermissionTo('view feed inventory', 'api', $farm)
            || $user->hasPermissionTo('view inventory', 'api', $farm);
    }

    protected function ensureCanManage(Request $request, Farm $farm): bool
    {
        $user = $request->user();
        return $user->hasPermissionTo('manage feed inventory', 'api', $farm)
            || $user->hasPermissionTo('manage inventory', 'api', $farm)
            || $user->hasPermissionTo('update feed products', 'api', $farm);
    }

    protected function ensureProductInFarm(Farm $farm, PoultryFeedProduct $product): bool
    {
        return $product->farm_id === null || $product->farm_id === $farm->id;
    }

    public function analyze(Request $request, $farm, PoultryFeedProduct $product)
    {
        $farm = Farm::findOrFail($farm);

        if (!$this->ensureCanView($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to analyse feed formulas');
        }

        if (!$this->ensureProductInFarm($farm, $product)) {
            return $this->sendNotFoundError('Feed product not found in this farm');
        }

        $profile = $this->service->calculateProfile($product);
        $analysisNote = $this->service->analyzeOnly($product);

        return $this->sendResponse([
            'nutritional_profile' => $profile,
            'ai_analysis' => $analysisNote,
            'ai_available' => $analysisNote !== null,
        ], 'Feed formula analysed successfully');
    }

    /**
     * AI-assisted feed formulation from scratch.
     */
    public function formulate(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);

        if (!$this->ensureCanManage($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to formulate feed');
        }

        $validator = Validator::make($request->all(), [
            'feed_type_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'target_profile' => 'nullable|array',
            'target_profile.crude_protein' => 'nullable|numeric|min:0|max:100',
            'target_profile.crude_fat' => 'nullable|numeric|min:0|max:100',
            'target_profile.crude_fiber' => 'nullable|numeric|min:0|max:100',
            'target_profile.calcium' => 'nullable|numeric|min:0|max:100',
            'target_profile.phosphorus' => 'nullable|numeric|min:0|max:100',
            'target_profile.metabolizable_energy' => 'nullable|numeric|min:0',
            'target_profile.moisture' => 'nullable|numeric|min:0|max:100',
            'target_profile.ash' => 'nullable|numeric|min:0|max:100',
            'component_ids' => 'nullable|array',
            'component_ids.*' => 'integer|exists:feed_components,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $feedTypeName = $request->input('feed_type_name');
        $description = $request->input('description');
        $targetProfile = $request->input('target_profile', []);
        $componentIds = $request->input('component_ids', []);

        // Load selected components from the DB
        $selectedComponents = [];
        if (!empty($componentIds)) {
            $selectedComponents = FeedComponent::where(function ($q) use ($farm) {
                $q->where('farm_id', $farm->id)->orWhereNull('farm_id');
            })->whereIn('id', $componentIds)->get()->all();
        }

        $result = $this->service->formulate($feedTypeName, $description, $targetProfile, $selectedComponents);

        if ($result === null) {
            return $this->sendResponse([
                'ai_available' => false,
            ], 'AI formulation is not available (missing API key or provider error)');
        }

        return $this->sendResponse([
            'ai_available' => true,
            'ai' => $result,
        ], 'Feed formula generated successfully');
    }

    /**
     * Revise an already-generated AI formula using a remodification message (chat-like).
     *
     * Expects the latest AI text (containing the formula) and a user message describing
     * the desired changes. Optionally includes a multi-turn message history.
     */
    public function revise(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);

        if (!$this->ensureCanManage($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to revise feed formulation');
        }

        $validator = Validator::make($request->all(), [
            'feed_type_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'target_profile' => 'nullable|array',
            'target_profile.crude_protein' => 'nullable|numeric|min:0|max:100',
            'target_profile.crude_fat' => 'nullable|numeric|min:0|max:100',
            'target_profile.crude_fiber' => 'nullable|numeric|min:0|max:100',
            'target_profile.calcium' => 'nullable|numeric|min:0|max:100',
            'target_profile.phosphorus' => 'nullable|numeric|min:0|max:100',
            'target_profile.metabolizable_energy' => 'nullable|numeric|min:0',
            'target_profile.moisture' => 'nullable|numeric|min:0|max:100',
            'target_profile.ash' => 'nullable|numeric|min:0|max:100',
            'component_ids' => 'nullable|array',
            'component_ids.*' => 'integer|exists:feed_components,id',
            'current_formula_text' => 'required|string|max:20000',
            'message' => 'required|string|max:2000',
            'messages' => 'nullable|array',
            'messages.*.role' => 'required_with:messages|string|in:user,assistant',
            'messages.*.content' => 'required_with:messages|string|max:4000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $feedTypeName = $request->input('feed_type_name');
        $description = $request->input('description');
        $targetProfile = $request->input('target_profile', []);
        $componentIds = $request->input('component_ids', []);
        $currentFormulaText = $request->input('current_formula_text');
        $message = $request->input('message');
        $messages = $request->input('messages', []);

        // Load selected components from the DB
        $selectedComponents = [];
        if (!empty($componentIds)) {
            $selectedComponents = FeedComponent::where(function ($q) use ($farm) {
                $q->where('farm_id', $farm->id)->orWhereNull('farm_id');
            })->whereIn('id', $componentIds)->get()->all();
        }

        $result = $this->service->reviseFormula(
            $feedTypeName,
            $description,
            $targetProfile,
            $selectedComponents,
            $currentFormulaText,
            $messages,
            $message
        );

        if ($result === null) {
            return $this->sendResponse([
                'ai_available' => false,
            ], 'AI revision is not available (missing API key or provider error)');
        }

        return $this->sendResponse([
            'ai_available' => true,
            'ai' => $result,
        ], 'Feed formula revised successfully');
    }

    public function recommend(Request $request, $farm, PoultryFeedProduct $product)
    {
        $farm = Farm::findOrFail($farm);

        if (!$this->ensureCanManage($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to request feed formula recommendations');
        }

        if (!$this->ensureProductInFarm($farm, $product)) {
            return $this->sendNotFoundError('Feed product not found in this farm');
        }

        $profile = $this->service->calculateProfile($product);
        $ai = $this->service->analyzeAndRecommend($product);

        if ($ai === null) {
            return $this->sendResponse([
                'nutritional_profile' => $profile,
                'ai_available' => false,
            ], 'Calculated profile, but AI recommendations are not available (missing API key or provider error)');
        }

        return $this->sendResponse([
            'nutritional_profile' => $profile,
            'ai_available' => true,
            'ai' => $ai,
        ], 'Feed formula analysed and recommendations generated successfully');
    }
}

