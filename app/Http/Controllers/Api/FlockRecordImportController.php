<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockRecordImport;
use App\Models\FlockRecordImportItem;
use App\Services\FlockRecordImport\FlockRecordImportAiService;
use App\Services\FlockRecordImport\FlockRecordImportCommitService;
use App\Services\FlockRecordImport\FlockRecordImportParser;
use App\Services\FlockRecordImport\FlockRecordImportSchema;
use App\Services\FlockRecordImport\FlockRecordImportTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class FlockRecordImportController extends ApiController
{
    public function __construct(
        private readonly FlockRecordImportParser $parser,
        private readonly FlockRecordImportAiService $aiService,
        private readonly FlockRecordImportCommitService $commitService,
        private readonly FlockRecordImportTemplateService $templateService,
    ) {
    }

    public function template(Request $request, $farm, $flock)
    {
        [$farmModel, $flockModel, $error] = $this->resolveFarmFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        if (! $this->canViewAny($request, $farmModel)) {
            return $this->sendUnauthorizedError('Unauthorized to download import template');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'fri_tpl_').'.xlsx';
        $this->templateService->writeToPath($tmp);

        return response()->download(
            $tmp,
            'flock-record-import-template.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    public function store(Request $request, $farm, $flock)
    {
        [$farmModel, $flockModel, $error] = $this->resolveFarmFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'method' => 'required|in:file,ai',
            'file' => 'required|file|max:10240',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $method = $request->input('method');
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        if ($method === FlockRecordImport::METHOD_AI) {
            if (! in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xlsx', 'csv'], true)) {
                return $this->sendValidationError('Validation failed', [
                    'file' => ['AI import accepts PDF, images, XLSX, or CSV'],
                ]);
            }
            $entitlement = app(\App\Services\FarmEntitlementService::class);
            $denial = $entitlement->denialFor($farmModel, \App\Services\FarmEntitlementService::ACTION_USE_AI);
            if ($denial !== null) {
                return response()->json([
                    'success' => false,
                    'message' => $denial['message'],
                    'code' => $denial['code'],
                    'upgrade_url' => config('subscription.billing_url'),
                ], $denial['status']);
            }
        } else {
            if (! in_array($ext, ['xlsx', 'csv'], true)) {
                return $this->sendValidationError('Validation failed', [
                    'file' => ['File import accepts XLSX or CSV'],
                ]);
            }
            if (! $this->canCreateAny($request, $farmModel)) {
                return $this->sendUnauthorizedError('Unauthorized to import flock records');
            }
        }

        $sourceType = match ($ext) {
            'pdf' => 'pdf',
            'xlsx' => 'xlsx',
            'csv' => 'csv',
            default => 'image',
        };

        $path = $file->store('flock-record-imports', 'public');

        $draft = FlockRecordImport::create([
            'farm_id' => $farmModel->id,
            'flock_id' => $flockModel->id,
            'created_by' => $request->user()->id,
            'source_method' => $method,
            'source_type' => $sourceType,
            'source_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'status' => FlockRecordImport::STATUS_DRAFT,
        ]);

        $warnings = [];

        if ($method === FlockRecordImport::METHOD_AI) {
            $result = $this->aiService->extractAndPopulate($draft, $farmModel);
            $warnings = $result['warnings'] ?? [];

            return $this->sendResponse([
                'draft' => $draft->fresh()->load('items'),
                'ai_available' => $result['ai_available'] ?? false,
                'warnings' => $warnings,
            ], 'Flock record import draft created', 201);
        }

        try {
            $parsed = $this->parser->parseFile(
                Storage::disk('public')->path($path),
                $ext,
                $farmModel
            );
            $items = $this->parser->applyOverlapRules($parsed['items']);
            $warnings = $parsed['warnings'];

            if (count($items) > FlockRecordImportSchema::MAX_ITEMS) {
                $warnings[] = 'Truncated to '.FlockRecordImportSchema::MAX_ITEMS.' rows.';
                $items = array_slice($items, 0, FlockRecordImportSchema::MAX_ITEMS);
            }

            DB::transaction(function () use ($draft, $items) {
                foreach ($items as $item) {
                    $draft->items()->create($item);
                }
            });
        } catch (\Throwable $e) {
            $draft->update(['status' => FlockRecordImport::STATUS_FAILED]);

            return $this->sendError('Failed to parse spreadsheet: '.$e->getMessage(), [], 422);
        }

        return $this->sendResponse([
            'draft' => $draft->fresh()->load('items'),
            'ai_available' => false,
            'warnings' => $warnings,
        ], 'Flock record import draft created', 201);
    }

    public function show(Request $request, $farm, $flock, $id)
    {
        [$farmModel, $flockModel, $error] = $this->resolveFarmFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        if (! $this->canViewAny($request, $farmModel)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock record imports');
        }

        $draft = $this->findDraft($farmModel->id, $flockModel->id, $id);

        return $this->sendResponse($draft->load('items'), 'Flock record import retrieved');
    }

    public function update(Request $request, $farm, $flock, $id)
    {
        [$farmModel, $flockModel, $error] = $this->resolveFarmFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        if (! $this->canCreateAny($request, $farmModel)) {
            return $this->sendUnauthorizedError('Unauthorized to update flock record imports');
        }

        $draft = $this->findDraft($farmModel->id, $flockModel->id, $id);
        if ($draft->status !== FlockRecordImport::STATUS_DRAFT) {
            return $this->sendValidationError('Invalid status', ['status' => ['Only drafts can be updated']]);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|max:'.FlockRecordImportSchema::MAX_ITEMS,
            'items.*.id' => 'nullable|integer',
            'items.*.record_type' => 'required|in:'.implode(',', FlockRecordImportItem::RECORD_TYPES),
            'items.*.payload' => 'required|array',
            'items.*.confidence' => 'nullable|numeric',
            '_delete_ids' => 'nullable|array',
            '_delete_ids.*' => 'integer',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $normalized = [];
        foreach ($request->input('items', []) as $i => $row) {
            $item = $this->parser->normalizeRow(
                $row['record_type'],
                $row['payload'],
                $farmModel,
                $i
            );
            if (isset($row['confidence'])) {
                $item['confidence'] = $row['confidence'];
            }
            $item['_id'] = $row['id'] ?? null;
            $normalized[] = $item;
        }
        $normalized = $this->parser->applyOverlapRules($normalized);

        DB::transaction(function () use ($draft, $normalized, $request) {
            $deleteIds = $request->input('_delete_ids', []);
            if ($deleteIds !== []) {
                $draft->items()->whereIn('id', $deleteIds)->delete();
            }

            $keepIds = [];
            foreach ($normalized as $item) {
                $id = $item['_id'] ?? null;
                unset($item['_id']);
                if ($id) {
                    $existing = $draft->items()->where('id', $id)->first();
                    if ($existing) {
                        $existing->update($item);
                        $keepIds[] = $existing->id;
                        continue;
                    }
                }
                $created = $draft->items()->create($item);
                $keepIds[] = $created->id;
            }

            // Optional full replace: remove items not present when client sends complete list without delete ids
            if ($request->boolean('replace_all', true)) {
                $draft->items()->whereNotIn('id', $keepIds)->delete();
            }
        });

        return $this->sendResponse($draft->fresh()->load('items'), 'Flock record import updated');
    }

    public function extract(Request $request, $farm, $flock, $id)
    {
        [$farmModel, $flockModel, $error] = $this->resolveFarmFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $draft = $this->findDraft($farmModel->id, $flockModel->id, $id);
        if ($draft->status !== FlockRecordImport::STATUS_DRAFT) {
            return $this->sendValidationError('Invalid status', ['status' => ['Only drafts can be re-extracted']]);
        }
        if ($draft->source_method !== FlockRecordImport::METHOD_AI) {
            return $this->sendValidationError('Invalid method', ['method' => ['Only AI drafts can be re-extracted']]);
        }

        $result = $this->aiService->extractAndPopulate($draft, $farmModel);

        return $this->sendResponse([
            'draft' => $draft->fresh()->load('items'),
            'ai_available' => $result['ai_available'] ?? false,
            'warnings' => $result['warnings'] ?? [],
        ], 'AI extraction completed');
    }

    public function confirm(Request $request, $farm, $flock, $id)
    {
        [$farmModel, $flockModel, $error] = $this->resolveFarmFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $draft = $this->findDraft($farmModel->id, $flockModel->id, $id);
        if ($draft->status !== FlockRecordImport::STATUS_DRAFT) {
            return $this->sendValidationError('Invalid status', ['status' => ['Only drafts can be confirmed']]);
        }

        $types = $draft->items()->pluck('record_type')->unique()->all();
        foreach ($types as $type) {
            $permission = FlockRecordImportSchema::permissionFor($type);
            if (! $request->user()->can($permission, 'api', $farmModel->id)
                && ! $request->user()->hasPermissionTo($permission, 'api', $farmModel)) {
                return $this->sendUnauthorizedError("Missing permission to create {$type} records ({$permission})");
            }
        }

        $summary = $this->commitService->confirm($draft, $farmModel, $flockModel, $request->user());

        return $this->sendResponse([
            'draft' => $draft->fresh()->load('items'),
            'summary' => $summary,
        ], 'Flock record import confirmed');
    }

    public function destroy(Request $request, $farm, $flock, $id)
    {
        [$farmModel, $flockModel, $error] = $this->resolveFarmFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        if (! $this->canCreateAny($request, $farmModel)) {
            return $this->sendUnauthorizedError('Unauthorized to delete flock record imports');
        }

        $draft = $this->findDraft($farmModel->id, $flockModel->id, $id);
        if ($draft->source_path) {
            Storage::disk('public')->delete($draft->source_path);
        }
        $draft->delete();

        return $this->sendResponse(null, 'Flock record import deleted');
    }

    private function findDraft(int $farmId, int $flockId, $id): FlockRecordImport
    {
        return FlockRecordImport::query()
            ->where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->findOrFail($id);
    }

    /**
     * @return array{0: ?Farm, 1: ?Flock, 2: mixed}
     */
    private function resolveFarmFlock(Request $request, $farm, $flock): array
    {
        $farmModel = Farm::findOrFail($farm);
        $flockModel = Flock::where('farm_id', $farmModel->id)->findOrFail($flock);

        if ($response = $this->ensureFlockIsActive($flockModel)) {
            return [null, null, $response];
        }

        return [$farmModel, $flockModel, null];
    }

    private function canCreateAny(Request $request, Farm $farm): bool
    {
        $user = $request->user();
        foreach (FlockRecordImportItem::RECORD_TYPES as $type) {
            $permission = FlockRecordImportSchema::permissionFor($type);
            if ($user->can($permission, 'api', $farm->id) || $user->hasPermissionTo($permission, 'api', $farm)) {
                return true;
            }
        }

        return false;
    }

    private function canViewAny(Request $request, Farm $farm): bool
    {
        return $this->canCreateAny($request, $farm)
            || $request->user()->can('view flocks', 'api', $farm->id)
            || $request->user()->hasPermissionTo('view flocks', 'api', $farm);
    }
}
