<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\FarmFeedTypeAgeRange;
use App\Models\PoultryFeedType;
use App\Services\FeedAgeRangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class FeedTypeAgeRangeController extends ApiController
{
    private function userCan(string $permission, Farm $farm): bool
    {
        try {
            return (bool) auth()->user()?->can($permission, 'api', $farm->id);
        } catch (\Throwable) {
            return false;
        }
    }

    private function canViewFeedAgeRanges(Farm $farm): bool
    {
        return $this->userCan('view feed types', $farm)
            || $this->userCan('manage farm settings', $farm)
            || $this->userCan('view farm', $farm);
    }

    private function canUpdateFeedAgeRanges(Farm $farm): bool
    {
        return $this->userCan('update feed types', $farm)
            || $this->userCan('manage farm settings', $farm);
    }

    public function index(Request $request, $farmId, FeedAgeRangeService $ageRangeService)
    {
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$this->canViewFeedAgeRanges($farm)) {
            return $this->sendError('You do not have permission to view feed age ranges', [], 403);
        }

        $feedTypes = PoultryFeedType::with('poultryType')
            ->where(function ($q) use ($farm) {
                $q->where('farm_id', $farm->id)
                    ->orWhere('type', 'default');
            })
            ->orderBy('poultry_type_id')
            ->orderBy('name')
            ->get();

        $enriched = $ageRangeService->attachEffectiveRanges($feedTypes, (int) $farm->id);

        return $this->sendResponse($enriched->values(), 'Feed age ranges retrieved successfully');
    }

    /**
     * Replace farm overrides for feed type age ranges.
     * Payload: { ranges: [{ poultry_feed_type_id, start_age, end_age|null }] }
     * Rows omitted from the payload are deleted (cleared back to the global default).
     */
    public function update(Request $request, $farmId, FeedAgeRangeService $ageRangeService)
    {
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$this->canUpdateFeedAgeRanges($farm)) {
            return $this->sendError('You do not have permission to update feed age ranges', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'ranges' => 'required|array',
            'ranges.*.poultry_feed_type_id' => 'required|integer|exists:poultry_feed_types,id',
            'ranges.*.start_age' => 'required|integer|min:0',
            'ranges.*.end_age' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $ranges = $validator->validated()['ranges'];

        foreach ($ranges as $idx => $range) {
            $start = (int) $range['start_age'];
            $endRaw = $range['end_age'] ?? null;
            if ($endRaw !== null && (int) $endRaw < $start) {
                return $this->sendValidationError('Validation Error', [
                    "ranges.{$idx}.end_age" => ['end_age must be greater than or equal to start_age (or null for open-ended).'],
                ]);
            }
        }

        $feedTypeIds = collect($ranges)->pluck('poultry_feed_type_id')->unique()->values();
        $visibleIds = PoultryFeedType::whereIn('id', $feedTypeIds)
            ->where(function ($q) use ($farm) {
                $q->where('farm_id', $farm->id)
                    ->orWhere('type', 'default');
            })
            ->pluck('id')
            ->all();

        $invisible = $feedTypeIds->diff($visibleIds);
        if ($invisible->isNotEmpty()) {
            return $this->sendError('One or more feed types are not available for this farm', [], 422);
        }

        try {
            DB::beginTransaction();

            $keepIds = [];

            foreach ($ranges as $range) {
                $feedTypeId = (int) $range['poultry_feed_type_id'];
                $endRaw = $range['end_age'] ?? null;
                $end = $endRaw === null || $endRaw === '' ? null : (int) $endRaw;

                FarmFeedTypeAgeRange::updateOrCreate(
                    [
                        'farm_id' => $farm->id,
                        'poultry_feed_type_id' => $feedTypeId,
                    ],
                    [
                        'start_age' => (int) $range['start_age'],
                        'end_age' => $end,
                    ]
                );

                $keepIds[] = $feedTypeId;
            }

            $deleteQuery = FarmFeedTypeAgeRange::where('farm_id', $farm->id);
            if (count($keepIds) > 0) {
                $deleteQuery->whereNotIn('poultry_feed_type_id', $keepIds);
            }
            $deleteQuery->delete();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Failed to update feed age ranges: ' . $e->getMessage(), [], 500);
        }

        $feedTypes = PoultryFeedType::with('poultryType')
            ->where(function ($q) use ($farm) {
                $q->where('farm_id', $farm->id)
                    ->orWhere('type', 'default');
            })
            ->orderBy('poultry_type_id')
            ->orderBy('name')
            ->get();

        $enriched = $ageRangeService->attachEffectiveRanges($feedTypes, (int) $farm->id);

        return $this->sendResponse($enriched->values(), 'Feed age ranges updated successfully');
    }
}
