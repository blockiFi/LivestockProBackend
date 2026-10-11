<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\PoultryFlockWeightReport;
use App\Models\PoultryHouse;
use App\Services\WeightSampleExtractionService;
use App\Services\WeightStatisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FlockWeightReportController extends ApiController
{
    public function __construct(
        private readonly WeightStatisticsService $stats,
    ) {
    }

    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock weight reports');
        }
        $query = PoultryFlockWeightReport::where('farm_id', $farm->id)
            ->with(['recordedBy:id,name', 'house:id,name']);
        if ($request->boolean('with_samples')) {
            $query->with('samples');
        }
        if ($request->has('flock_id')) {
            $query->where('flock_id', $request->flock_id);
        }
        if ($request->filled('sex')) {
            $query->where('sex', $request->sex);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('report_date', 'like', "%{$search}%");
        }
        $sortField = in_array($request->input('sort_by'), ['report_date', 'average_weight', 'uniformity_percent', 'age_days', 'created_at'], true)
            ? $request->input('sort_by')
            : 'report_date';
        $sortDirection = $request->input('sort_direction') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDirection);
        $perPage = $request->input('per_page', 10);
        $reports = $query->paginate($perPage);
        return $this->sendResponse($reports, 'Flock weight reports retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create flock weight reports');
        }

        $hasSamples = is_array($request->input('samples')) && count($request->input('samples')) > 0;

        $rules = array_merge($this->sessionRules(), [
            'flock_id' => 'required|exists:flocks,id',
            'report_date' => 'required|date',
            'number_of_birds' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:2000',
        ], $hasSamples ? $this->sampleRules(true) : [
            'average_weight' => 'required|numeric|min:0',
            'min_weight' => 'required|numeric|min:0',
            'max_weight' => 'required|numeric|min:0',
            'sample_size' => 'required|integer|min:0',
            'target_weight_g' => 'nullable|numeric|min:1',
            'birds_within_band' => 'nullable|integer|min:0|lte:sample_size',
        ]);

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->all());
        }

        $flock = Flock::where('farm_id', $farm->id)->find($request->flock_id);
        if (!$flock) {
            return $this->sendNotFoundError('Flock not found in this farm');
        }
        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }
        if ($error = $this->validateHouse($request, $farm)) {
            return $error;
        }

        $reportDate = (string) $request->report_date;

        $report = DB::transaction(function () use ($request, $farm, $flock, $user, $hasSamples, $reportDate) {
            $report = new PoultryFlockWeightReport(array_merge(
                $this->sessionPayload($request),
                [
                    'farm_id' => $farm->id,
                    'flock_id' => $flock->id,
                    'report_date' => $reportDate,
                    'age_days' => $this->stats->ageDaysOn($flock, $reportDate),
                    'number_of_birds' => $request->filled('number_of_birds')
                        ? (int) $request->number_of_birds
                        : $flock->birdCountOnDate($reportDate),
                    'notes' => $request->input('notes'),
                    'recorded_by' => $user->id,
                ]
            ));

            if ($hasSamples) {
                $report->data_source = $request->input('data_source', PoultryFlockWeightReport::SOURCE_MANUAL);
                $report->source_file_path = $request->input('source_file_path');
                $report->save();
                $this->replaceSamples($report, $request->input('samples'));
                $this->applyStatistics($report);
            } else {
                $report->fill([
                    'average_weight' => $request->average_weight,
                    'min_weight' => $request->min_weight,
                    'max_weight' => $request->max_weight,
                    'sample_size' => $request->sample_size,
                    'data_source' => PoultryFlockWeightReport::SOURCE_SUMMARY_ONLY,
                ]);
                $this->applySummaryStatistics($report, $this->countedWithinBand($request));
            }

            $report->save();

            return $report;
        });

        $report->load(['recordedBy:id,name', 'house:id,name', 'samples']);
        return $this->sendResponse($report, 'Flock weight report created successfully', 201);
    }

    public function show(Request $request, $farm, PoultryFlockWeightReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock weight reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock weight report not found in this farm');
        }
        $report->load(['recordedBy:id,name', 'house:id,name', 'samples']);
        return $this->sendResponse($report, 'Flock weight report retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryFlockWeightReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update flock weight reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock weight report not found in this farm');
        }

        $flock = Flock::find($report->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $hasSamples = is_array($request->input('samples')) && count($request->input('samples')) > 0;

        $rules = array_merge($this->sessionRules(), [
            'report_date' => 'sometimes|date',
            'number_of_birds' => 'sometimes|integer|min:0',
            'notes' => 'nullable|string|max:2000',
            'average_weight' => 'sometimes|numeric|min:0',
            'min_weight' => 'sometimes|numeric|min:0',
            'max_weight' => 'sometimes|numeric|min:0',
            'sample_size' => 'sometimes|integer|min:0',
            'birds_within_band' => 'nullable|integer|min:0',
        ], $this->sampleRules(false));

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->all());
        }
        if ($error = $this->validateHouse($request, $farm)) {
            return $error;
        }

        DB::transaction(function () use ($request, $report, $flock, $hasSamples) {
            $report->fill($this->sessionPayload($request, true));

            foreach (['report_date', 'number_of_birds', 'notes'] as $field) {
                if ($request->has($field)) {
                    $report->{$field} = $request->input($field);
                }
            }
            if ($request->has('report_date') && $flock) {
                $report->age_days = $this->stats->ageDaysOn($flock, (string) $request->report_date);
            }

            if ($hasSamples) {
                if ($request->has('data_source')) {
                    $report->data_source = $request->input('data_source');
                } elseif ($report->data_source === PoultryFlockWeightReport::SOURCE_SUMMARY_ONLY) {
                    $report->data_source = PoultryFlockWeightReport::SOURCE_MANUAL;
                }
                if ($request->has('source_file_path')) {
                    $report->source_file_path = $request->input('source_file_path');
                }
                $report->save();
                $this->replaceSamples($report, $request->input('samples'));
                $this->applyStatistics($report);
            } elseif ($report->samples()->exists()) {
                $this->applyStatistics($report);
            } else {
                foreach (['average_weight', 'min_weight', 'max_weight', 'sample_size'] as $field) {
                    if ($request->has($field)) {
                        $report->{$field} = $request->input($field);
                    }
                }
                $counted = $request->has('birds_within_band')
                    ? $this->countedWithinBand($request)
                    : ($report->uniformity_method === PoultryFlockWeightReport::UNIFORMITY_COUNTED ? $report->birds_within_band : null);
                $this->applySummaryStatistics($report, $counted);
            }

            $report->save();
        });

        $report->refresh()->load(['recordedBy:id,name', 'house:id,name', 'samples']);
        return $this->sendResponse($report, 'Flock weight report updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFlockWeightReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete flock weight reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock weight report not found in this farm');
        }

        $flock = Flock::find($report->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $report->delete();
        return $this->sendResponse(null, 'Flock weight report deleted successfully');
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock weight reports');
        }
        $query = PoultryFlockWeightReport::where('farm_id', $farm->id);
        if ($request->filled('flock_id')) {
            $query->where('flock_id', $request->flock_id);
        }
        $statistics = [
            'total_weight_reports' => (clone $query)->count(),
            'average_weight' => (clone $query)->avg('average_weight'),
            'average_uniformity_percent' => (clone $query)->whereNotNull('uniformity_percent')->avg('uniformity_percent'),
            'average_cv_percent' => (clone $query)->whereNotNull('cv_percent')->avg('cv_percent'),
        ];
        return $this->sendResponse($statistics, 'Flock weight report statistics retrieved successfully');
    }

    /**
     * Most recent target weight for a flock (optionally by sex), used to pre-fill the next session.
     */
    public function lastTarget(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock weight reports');
        }

        $validator = Validator::make($request->all(), [
            'flock_id' => 'required|integer',
            'sex' => ['nullable', Rule::in(PoultryFlockWeightReport::SEXES)],
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->all());
        }

        $base = PoultryFlockWeightReport::where('farm_id', $farm->id)
            ->where('flock_id', $request->flock_id)
            ->whereNotNull('target_weight_g')
            ->orderByDesc('report_date')
            ->orderByDesc('id');

        $report = null;
        if ($request->filled('sex')) {
            $report = (clone $base)->where('sex', $request->sex)->first();
        }
        $report ??= $base->first();

        return $this->sendResponse([
            'target_weight_g' => $report?->target_weight_g,
            'report_date' => $report?->report_date?->toDateString(),
            'age_days' => $report?->age_days,
            'uniformity_band_percent' => $report?->uniformity_band_percent,
        ], 'Last target weight retrieved successfully');
    }

    /**
     * AI-extract individual bird weights from a photo, PDF or spreadsheet. Returns a draft only.
     */
    public function extract(Request $request, $farm, WeightSampleExtractionService $extractor)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create flock weight reports');
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf,csv,txt,xlsx',
            'flock_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->all());
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if ($ext === 'txt') {
            $ext = 'csv';
        }

        $path = $file->store("flock-weight-sheets/{$farm->id}", 'public');
        $result = $extractor->extract($file->getRealPath(), $ext);

        $dataSource = $result['source_type'] === 'spreadsheet'
            ? PoultryFlockWeightReport::SOURCE_AI_FILE
            : PoultryFlockWeightReport::SOURCE_AI_IMAGE;

        $outliers = $this->stats->outlierFlags(array_column($result['samples'], 'weight_g'));
        $samples = array_map(
            fn (array $s, int $i) => $s + ['is_outlier' => $outliers[$i] ?? false],
            $result['samples'],
            array_keys($result['samples'])
        );

        return $this->sendResponse(array_merge($result, [
            'samples' => $samples,
            'data_source' => $dataSource,
            'source_file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
        ]), $result['ok'] ? 'Weights extracted' : 'No weights could be extracted');
    }

    private function sessionRules(): array
    {
        return [
            'house_id' => 'nullable|integer|exists:poultry_houses,id',
            'pen_label' => 'nullable|string|max:100',
            'sex' => ['nullable', Rule::in(PoultryFlockWeightReport::SEXES)],
            'weighing_method' => ['nullable', Rule::in(PoultryFlockWeightReport::WEIGHING_METHODS)],
            'scale_reference' => 'nullable|string|max:100',
            'weighed_at_time' => 'nullable|date_format:H:i',
            'uniformity_band_percent' => 'nullable|numeric|in:5,10,15',
        ];
    }

    private function sampleRules(bool $requireTarget): array
    {
        return [
            'samples' => 'sometimes|array|min:1|max:2000',
            'samples.*.weight_g' => 'required_with:samples|numeric|min:1|max:20000',
            'samples.*.sex' => ['nullable', Rule::in(['male', 'female'])],
            'samples.*.excluded' => 'nullable|boolean',
            'samples.*.note' => 'nullable|string|max:255',
            'target_weight_g' => ($requireTarget ? 'required' : 'nullable').'|numeric|min:1|max:20000',
            'data_source' => ['nullable', Rule::in([
                PoultryFlockWeightReport::SOURCE_MANUAL,
                PoultryFlockWeightReport::SOURCE_AI_IMAGE,
                PoultryFlockWeightReport::SOURCE_AI_FILE,
            ])],
            'source_file_path' => 'nullable|string|max:255',
        ];
    }

    private function sessionPayload(Request $request, bool $partial = false): array
    {
        $fields = [
            'house_id', 'pen_label', 'sex', 'weighing_method', 'scale_reference',
            'weighed_at_time', 'uniformity_band_percent', 'target_weight_g',
        ];

        $payload = [];
        foreach ($fields as $field) {
            if ($partial && !$request->has($field)) {
                continue;
            }
            $payload[$field] = $request->input($field);
        }

        if (!$partial || $request->has('uniformity_band_percent')) {
            $payload['uniformity_band_percent'] = (float) ($request->input('uniformity_band_percent')
                ?: WeightStatisticsService::DEFAULT_BAND_PERCENT);
        }
        if (!$partial && empty($payload['sex'])) {
            $payload['sex'] = 'mixed';
        }

        return $payload;
    }

    private function countedWithinBand(Request $request): ?int
    {
        return $request->filled('birds_within_band') ? (int) $request->input('birds_within_band') : null;
    }

    private function validateHouse(Request $request, Farm $farm)
    {
        if (!$request->filled('house_id')) {
            return null;
        }
        $belongs = PoultryHouse::where('id', $request->house_id)->where('farm_id', $farm->id)->exists();

        return $belongs ? null : $this->sendValidationError('Validation failed', ['The selected house does not belong to this farm.']);
    }

    /**
     * @param  list<array<string, mixed>>  $samples
     */
    private function replaceSamples(PoultryFlockWeightReport $report, array $samples): void
    {
        $report->samples()->delete();

        $weights = array_map(fn ($s) => (float) ($s['weight_g'] ?? 0), $samples);
        $outliers = $this->stats->outlierFlags($weights);

        $rows = [];
        $now = now();
        foreach (array_values($samples) as $i => $sample) {
            $rows[] = [
                'weight_report_id' => $report->id,
                'sequence' => $i + 1,
                'weight_g' => round((float) $sample['weight_g'], 1),
                'sex' => $sample['sex'] ?? null,
                'is_outlier' => $outliers[$i] ?? false,
                'excluded' => (bool) ($sample['excluded'] ?? false),
                'note' => $sample['note'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $report->samples()->insert($chunk);
        }
    }

    /**
     * Derive statistics from stored samples and mirror them into the legacy kg columns.
     */
    private function applyStatistics(PoultryFlockWeightReport $report): void
    {
        $weights = $report->samples()->where('excluded', false)->pluck('weight_g')->map(fn ($w) => (float) $w)->all();
        $band = (float) ($report->uniformity_band_percent ?: WeightStatisticsService::DEFAULT_BAND_PERCENT);
        $target = $report->target_weight_g ? (float) $report->target_weight_g : null;
        $s = $this->stats->compute($weights, $target, $band);

        $report->fill([
            'sample_size' => $s['count'],
            'average_weight_g' => $s['mean'],
            'median_weight_g' => $s['median'],
            'std_dev_g' => $s['std_dev'],
            'cv_percent' => $s['cv_percent'],
            'uniformity_percent' => $s['uniformity_percent'],
            'uniformity_band_percent' => $band,
            'birds_within_band' => $s['birds_within_band'],
            'uniformity_method' => $s['uniformity_percent'] !== null ? PoultryFlockWeightReport::UNIFORMITY_MEASURED : null,
            'deviation_from_target_g' => $s['deviation_g'],
            'deviation_from_target_percent' => $s['deviation_percent'],
            'average_weight' => $s['mean'] !== null ? round($s['mean'] / 1000, 2) : 0,
            'min_weight' => $s['min'] !== null ? round($s['min'] / 1000, 2) : 0,
            'max_weight' => $s['max'] !== null ? round($s['max'] / 1000, 2) : 0,
        ]);

        $report->daily_gain_g = $s['mean'] !== null
            ? $this->stats->dailyGain(
                (int) $report->flock_id,
                $report->report_date->toDateString(),
                (float) $s['mean'],
                $report->sex,
                $report->id
            )
            : null;
    }

    /**
     * For summary-only reports, derive what can be derived from the average (kg) alone.
     */
    private function applySummaryStatistics(PoultryFlockWeightReport $report, ?int $countedWithinBand = null): void
    {
        $averageG = (float) $report->average_weight * 1000;
        $report->average_weight_g = $averageG > 0 ? round($averageG, 1) : null;
        $report->median_weight_g = null;

        $band = (float) ($report->uniformity_band_percent ?: WeightStatisticsService::DEFAULT_BAND_PERCENT);
        $uniformity = $this->stats->estimateFromSummary(
            $averageG > 0 ? $averageG : null,
            $report->min_weight !== null ? (float) $report->min_weight * 1000 : null,
            $report->max_weight !== null ? (float) $report->max_weight * 1000 : null,
            (int) $report->sample_size,
            $band,
            $countedWithinBand
        );
        $report->fill([
            'std_dev_g' => $uniformity['std_dev'],
            'cv_percent' => $uniformity['cv_percent'],
            'uniformity_percent' => $uniformity['uniformity_percent'],
            'uniformity_band_percent' => $band,
            'birds_within_band' => $uniformity['birds_within_band'],
            'uniformity_method' => $uniformity['method'],
        ]);

        $target = $report->target_weight_g ? (float) $report->target_weight_g : null;
        if ($target && $averageG > 0) {
            $report->deviation_from_target_g = round($averageG - $target, 1);
            $report->deviation_from_target_percent = round((($averageG - $target) / $target) * 100, 2);
        } else {
            $report->deviation_from_target_g = null;
            $report->deviation_from_target_percent = null;
        }

        $report->daily_gain_g = $averageG > 0 && $report->report_date
            ? $this->stats->dailyGain(
                (int) $report->flock_id,
                $report->report_date->toDateString(),
                $averageG,
                $report->sex,
                $report->id
            )
            : null;
    }
}
