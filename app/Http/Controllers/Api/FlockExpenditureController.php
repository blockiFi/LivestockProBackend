<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockExpenditure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FlockExpenditureController extends ApiController
{
    public function index(Request $request, $farmId, $flockId)
    {
        $farm = Farm::findOrFail($farmId);
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if (! $request->user()->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock expenditures');
        }

        $query = $this->filteredQuery($request, $farmId, $flockId);
        $expenditures = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->get();

        return $this->sendResponse($expenditures, 'Flock expenditures retrieved successfully');
    }

    public function summary(Request $request, $farmId, $flockId)
    {
        $farm = Farm::findOrFail($farmId);
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if (! $request->user()->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock expenditures');
        }

        $rows = $this->filteredQuery($request, $farmId, $flockId)->get();

        $byCategory = [];
        $autoTotal = 0.0;
        $manualTotal = 0.0;
        $byDate = [];

        foreach ($rows as $row) {
            $amount = (float) $row->amount;
            $category = (string) $row->category;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + $amount;

            if ($row->isManual()) {
                $manualTotal += $amount;
            } else {
                $autoTotal += $amount;
            }

            $dateKey = $row->date?->toDateString() ?? (string) $row->date;
            $byDate[$dateKey] = ($byDate[$dateKey] ?? 0) + $amount;
        }

        ksort($byDate);

        $birdCount = max(1, (int) ($flock->actual_quantity ?? $flock->quantity ?? 0));
        $totalCost = round(array_sum($byCategory), 2);

        $categoryBreakdown = collect($byCategory)
            ->map(fn (float $total, string $category) => [
                'category' => $category,
                'total_cost' => round($total, 2),
                'percentage' => $totalCost > 0 ? round(($total / $totalCost) * 100, 1) : 0,
            ])
            ->sortByDesc('total_cost')
            ->values()
            ->all();

        return $this->sendResponse([
            'total_cost' => $totalCost,
            'auto_total' => round($autoTotal, 2),
            'manual_total' => round($manualTotal, 2),
            'entry_count' => $rows->count(),
            'cost_per_bird' => round($totalCost / $birdCount, 2),
            'bird_count' => $birdCount,
            'by_category' => $categoryBreakdown,
            'cost_by_date' => collect($byDate)
                ->map(fn (float $total, string $date) => [
                    'date' => $date,
                    'total_cost' => round($total, 2),
                ])
                ->values()
                ->all(),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ], 'Flock expenditure summary retrieved successfully');
    }

    public function store(Request $request, $farmId, $flockId)
    {
        $farm = Farm::findOrFail($farmId);
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if (! $request->user()->hasPermissionTo('update flocks', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create flock expenditures');
        }

        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $data = $request->all();
        $data['farm_id'] = $farm->id;
        $data['flock_id'] = $flock->id;

        $validator = Validator::make($data, $this->validationRules());

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $expenditure = FlockExpenditure::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'category' => $data['category'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? null,
            'description' => $data['description'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'date' => $data['date'],
            'source_type' => 'manual',
            'source_id' => null,
            'created_by' => $request->user()->id,
        ]);

        return $this->sendResponse($expenditure, 'Flock expenditure created successfully', 201);
    }

    public function update(Request $request, $farmId, $flockId, $id)
    {
        $farm = Farm::findOrFail($farmId);
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if (! $request->user()->hasPermissionTo('update flocks', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update flock expenditures');
        }

        $expenditure = FlockExpenditure::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->findOrFail($id);

        if (! $expenditure->isManual()) {
            $disallowed = array_values(array_filter(
                ['category', 'amount', 'currency', 'description', 'payment_method', 'reference_no'],
                fn (string $field) => $request->has($field)
            ));

            if ($disallowed !== []) {
                return $this->sendValidationError('Validation failed', [
                    'date' => ['Only the date can be edited on auto-generated expenditures.'],
                ]);
            }

            $validator = Validator::make($request->all(), [
                'date' => 'required|date',
            ]);

            if ($validator->fails()) {
                return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
            }

            $newDate = $request->input('date');
            $expenditure->update([
                'date' => $newDate,
                'updated_by' => $request->user()->id,
            ]);
            $expenditure->syncSourceDate($newDate);

            return $this->sendResponse($expenditure, 'Flock expenditure date updated successfully');
        }

        $validator = Validator::make($request->all(), $this->validationRules(partial: true));

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $updateData = $request->only([
            'category',
            'amount',
            'currency',
            'description',
            'payment_method',
            'reference_no',
            'date',
        ]);
        $updateData['updated_by'] = $request->user()->id;

        $expenditure->update($updateData);

        return $this->sendResponse($expenditure, 'Flock expenditure updated successfully');
    }

    public function destroy(Request $request, $farmId, $flockId, $id)
    {
        $farm = Farm::findOrFail($farmId);
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        if (! $request->user()->hasPermissionTo('update flocks', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete flock expenditures');
        }

        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $expenditure = FlockExpenditure::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->findOrFail($id);

        if ($expenditure->source_type !== 'manual') {
            return $this->sendError('Auto-generated expenditures cannot be deleted', [], 422);
        }

        $expenditure->delete();

        return $this->sendResponse(null, 'Flock expenditure deleted successfully');
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'farm_id' => $partial ? 'sometimes|exists:farms,id' : 'required|exists:farms,id',
            'flock_id' => $partial ? 'sometimes|exists:flocks,id' : 'required|exists:flocks,id',
            'category' => [$required, Rule::in(FlockExpenditure::MANUAL_CATEGORIES)],
            'amount' => "{$required}|numeric|min:0.01",
            'currency' => 'nullable|string|max:3',
            'description' => 'nullable|string|max:255',
            'payment_method' => 'nullable|string|max:50',
            'reference_no' => 'nullable|string|max:100',
            'date' => "{$required}|date",
        ];
    }

    private function filteredQuery(Request $request, int $farmId, int $flockId)
    {
        $query = FlockExpenditure::where('farm_id', $farmId)
            ->where('flock_id', $flockId);

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        if ($request->filled('source')) {
            if ($request->source === 'manual') {
                $query->where(function ($q) {
                    $q->whereNull('source_type')->orWhere('source_type', 'manual');
                });
            } elseif ($request->source === 'auto') {
                $query->whereNotNull('source_type')->where('source_type', '!=', 'manual');
            }
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', $search)
                    ->orWhere('reference_no', 'like', $search)
                    ->orWhere('payment_method', 'like', $search);
            });
        }

        return $query;
    }
}
