<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\FarmShare;
use App\Services\FarmShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Share (copy) a farm's own schedule templates and feed formulations into
 * other farms the user belongs to.
 */
class FarmShareController extends ApiController
{
    public function __construct(private readonly FarmShareService $shares)
    {
    }

    /** GET /farms/{farm}/share-targets?kind=… — farms this user can share into. */
    public function targets(Request $request, $farm): JsonResponse
    {
        $farm = Farm::findOrFail($farm instanceof Farm ? $farm->id : $farm);
        $kind = (string) $request->query('kind', '');
        if (! in_array($kind, FarmShareService::KINDS, true)) {
            return $this->sendValidationError('Validation failed', ['kind' => ['kind must be one of: '.implode(', ', FarmShareService::KINDS)]]);
        }

        $user = $request->user();
        if (! $this->shares->userCan($user, $farm->id, $this->shares->sharePermissions($kind))) {
            return $this->sendUnauthorizedError('You do not have permission to share from this farm');
        }

        $farms = $this->shares->targetFarms($user, $farm, $kind)
            ->map(fn (Farm $f) => ['id' => $f->id, 'name' => $f->name]);

        return $this->sendResponse($farms, 'Share targets retrieved successfully');
    }

    /** POST /farms/{farm}/share — copy one item into the chosen farms. */
    public function share(Request $request, $farm): JsonResponse
    {
        $farm = Farm::findOrFail($farm instanceof Farm ? $farm->id : $farm);

        $validator = Validator::make($request->all(), [
            'kind' => ['required', Rule::in(FarmShareService::KINDS)],
            'id' => 'required|integer',
            'target_farm_ids' => 'required|array|min:1|max:50',
            'target_farm_ids.*' => 'integer|distinct',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $kind = $request->input('kind');
        $user = $request->user();

        if (! $this->shares->userCan($user, $farm->id, $this->shares->sharePermissions($kind))) {
            return $this->sendUnauthorizedError('You do not have permission to share from this farm');
        }

        $item = $this->shares->findSource($farm, $kind, (int) $request->input('id'));
        if (! $item) {
            return $this->sendNotFoundError('Only this farm\'s own schedules and feed products can be shared');
        }

        $allowed = $this->shares->targetFarms($user, $farm, $kind)->keyBy('id');
        $requested = collect($request->input('target_farm_ids'))->map(fn ($id) => (int) $id)->unique();
        $refused = $requested->reject(fn ($id) => $allowed->has($id))->values();
        if ($refused->isNotEmpty()) {
            return $this->sendValidationError('Validation failed', [
                'target_farm_ids' => ['You cannot share to farm(s): '.$refused->implode(', ').'. You need to be a member there with permission to create this item.'],
            ]);
        }

        $results = $requested->map(function (int $targetId) use ($allowed, $item, $farm, $user) {
            $target = Farm::find($targetId);
            $copy = $this->shares->copyTo($item, $farm, $target, $user);

            return [
                'farm_id' => $target->id,
                'farm_name' => $allowed[$targetId]->name,
                'id' => $copy->id,
                'name' => $copy->name ?? $copy->title,
            ];
        })->values();

        $count = $results->count();

        return $this->sendResponse($results, "Shared to {$count} ".($count === 1 ? 'farm' : 'farms'), 201);
    }

    // ── Sending to any farm by share code, with accept / decline ─────

    private function farm($farm): Farm
    {
        return Farm::findOrFail($farm instanceof Farm ? $farm->id : $farm);
    }

    private function canShareAny($user, Farm $farm): bool
    {
        return $this->shares->userCan($user, $farm->id, array_merge(
            $this->shares->sharePermissions(FarmShareService::KIND_SCHEDULE),
            $this->shares->sharePermissions(FarmShareService::KIND_FEED_PRODUCT)
        ));
    }

    /** @return list<string> kinds this user may accept on the farm */
    private function receivableKinds($user, Farm $farm): array
    {
        return array_values(array_filter(
            FarmShareService::KINDS,
            fn ($kind) => $this->shares->userCan($user, $farm->id, $this->shares->receivePermissions($kind))
        ));
    }

    private function present(FarmShare $share, array $receivable = []): array
    {
        return [
            'id' => $share->id,
            'kind' => $share->kind,
            'kind_label' => $this->shares->kindLabel($share),
            'item_name' => $share->item_name,
            'item_subtype' => $share->item_subtype,
            'item_count' => $share->item_count,
            'message' => $share->message,
            'status' => $share->status,
            'source_farm' => ['id' => $share->source_farm_id, 'name' => $share->sourceFarm?->name],
            'target_farm' => ['id' => $share->target_farm_id, 'name' => $share->targetFarm?->name],
            'shared_by' => $share->sharedBy ? ['id' => $share->sharedBy->id, 'name' => $share->sharedBy->name] : null,
            'responded_by' => $share->respondedBy ? ['id' => $share->respondedBy->id, 'name' => $share->respondedBy->name] : null,
            'responded_at' => $share->responded_at?->toIso8601String(),
            'accepted_item_id' => $share->accepted_item_id,
            'can_respond' => $share->status === FarmShare::STATUS_PENDING && in_array($share->kind, $receivable, true),
            'created_at' => $share->created_at?->toIso8601String(),
        ];
    }

    /** GET /farms/{farm}/share-code — the code other farms use to send to this farm. */
    public function code(Request $request, $farm): JsonResponse
    {
        $farm = $this->farm($farm);
        $user = $request->user();
        if (! $this->canShareAny($user, $farm) && $this->receivableKinds($user, $farm) === []) {
            return $this->sendUnauthorizedError('You do not have permission to see this farm\'s share code');
        }

        return $this->sendResponse(['code' => $this->shares->shareCodeFor($farm), 'farm_name' => $farm->name], 'Share code retrieved');
    }

    /** GET /farms/{farm}/share-lookup?code=… — confirm which farm a code belongs to before sending. */
    public function lookup(Request $request, $farm): JsonResponse
    {
        $farm = $this->farm($farm);
        if (! $this->canShareAny($request->user(), $farm)) {
            return $this->sendUnauthorizedError('You do not have permission to share from this farm');
        }
        $target = $this->shares->findFarmByCode((string) $request->query('code', ''));
        if (! $target) {
            return $this->sendNotFoundError('No farm has that share code');
        }
        if ($target->id === $farm->id) {
            return $this->sendValidationError('Validation failed', ['code' => ['That is this farm\'s own code.']]);
        }

        return $this->sendResponse(['id' => $target->id, 'name' => $target->name], 'Farm found');
    }

    /** POST /farms/{farm}/share/send — send an item to another farm to accept. */
    public function send(Request $request, $farm): JsonResponse
    {
        $farm = $this->farm($farm);
        $validator = Validator::make($request->all(), [
            'kind' => ['required', Rule::in(FarmShareService::KINDS)],
            'id' => 'required|integer',
            'farm_code' => 'required|string|max:32',
            'message' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $kind = $request->input('kind');
        $user = $request->user();
        if (! $this->shares->userCan($user, $farm->id, $this->shares->sharePermissions($kind))) {
            return $this->sendUnauthorizedError('You do not have permission to share from this farm');
        }
        $item = $this->shares->findSource($farm, $kind, (int) $request->input('id'));
        if (! $item) {
            return $this->sendNotFoundError('Only this farm\'s own schedules and feed products can be shared');
        }
        $target = $this->shares->findFarmByCode($request->input('farm_code'));
        if (! $target) {
            return $this->sendValidationError('Validation failed', ['farm_code' => ['No farm has that share code.']]);
        }
        if ($target->id === $farm->id) {
            return $this->sendValidationError('Validation failed', ['farm_code' => ['That is this farm\'s own code.']]);
        }
        $alreadyPending = FarmShare::where('kind', $kind)->where('source_item_id', $item->id)
            ->where('target_farm_id', $target->id)->where('status', FarmShare::STATUS_PENDING)->exists();
        if ($alreadyPending) {
            return $this->sendValidationError('Validation failed', ['farm_code' => ["{$target->name} has not answered the last time you sent this yet."]]);
        }

        $share = $this->shares->send($item, $kind, $farm, $target, $user, $request->input('message'));

        return $this->sendResponse($this->present($share->load(['sourceFarm', 'targetFarm', 'sharedBy'])), "Sent to {$target->name}", 201);
    }

    /** GET /farms/{farm}/shares/incoming */
    public function incoming(Request $request, $farm): JsonResponse
    {
        $farm = $this->farm($farm);
        $receivable = $this->receivableKinds($request->user(), $farm);
        if ($receivable === []) {
            return $this->sendUnauthorizedError('You do not have permission to receive shared items on this farm');
        }

        $shares = FarmShare::with(['sourceFarm', 'targetFarm', 'sharedBy', 'respondedBy'])
            ->where('target_farm_id', $farm->id)
            ->where('status', '!=', FarmShare::STATUS_CANCELLED)
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')->limit(100)->get()
            ->map(fn ($s) => $this->present($s, $receivable));

        return $this->sendResponse($shares, 'Incoming shares retrieved');
    }

    /** GET /farms/{farm}/shares/outgoing */
    public function outgoing(Request $request, $farm): JsonResponse
    {
        $farm = $this->farm($farm);
        if (! $this->canShareAny($request->user(), $farm)) {
            return $this->sendUnauthorizedError('You do not have permission to share from this farm');
        }

        $shares = FarmShare::with(['sourceFarm', 'targetFarm', 'sharedBy', 'respondedBy'])
            ->where('source_farm_id', $farm->id)
            ->latest('id')->limit(100)->get()
            ->map(fn ($s) => $this->present($s));

        return $this->sendResponse($shares, 'Sent shares retrieved');
    }

    private function incomingShare(Request $request, Farm $farm, $shareId): FarmShare|JsonResponse
    {
        $share = FarmShare::where('id', $shareId)->where('target_farm_id', $farm->id)->first();
        if (! $share) {
            return $this->sendNotFoundError('Share not found');
        }
        if (! $this->shares->userCan($request->user(), $farm->id, $this->shares->receivePermissions($share->kind))) {
            return $this->sendUnauthorizedError('You do not have permission to accept this kind of item on this farm');
        }
        if ($share->status !== FarmShare::STATUS_PENDING) {
            return $this->sendValidationError('This share has already been answered.');
        }

        return $share;
    }

    /** POST /farms/{farm}/shares/{share}/accept */
    public function accept(Request $request, $farm, $share): JsonResponse
    {
        $farm = $this->farm($farm);
        $share = $this->incomingShare($request, $farm, $share);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        try {
            $copy = $this->shares->accept($share, $request->user());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->sendError($e->getMessage(), [], $e->getStatusCode());
        }

        $share->refresh()->load(['sourceFarm', 'targetFarm', 'sharedBy', 'respondedBy']);

        return $this->sendResponse(
            array_merge($this->present($share), ['copy' => ['id' => $copy->id, 'name' => $copy->name ?? $copy->title]]),
            'Added to this farm'
        );
    }

    /** POST /farms/{farm}/shares/{share}/decline */
    public function decline(Request $request, $farm, $share): JsonResponse
    {
        $farm = $this->farm($farm);
        $share = $this->incomingShare($request, $farm, $share);
        if ($share instanceof JsonResponse) {
            return $share;
        }
        $share = $this->shares->decline($share->load('targetFarm'), $request->user());

        return $this->sendResponse($this->present($share->load(['sourceFarm', 'sharedBy', 'respondedBy'])), 'Declined');
    }

    /** POST /farms/{farm}/shares/{share}/cancel — take back a share the other farm has not answered. */
    public function cancel(Request $request, $farm, $share): JsonResponse
    {
        $farm = $this->farm($farm);
        $share = FarmShare::where('id', $share)->where('source_farm_id', $farm->id)->first();
        if (! $share) {
            return $this->sendNotFoundError('Share not found');
        }
        if (! $this->shares->userCan($request->user(), $farm->id, $this->shares->sharePermissions($share->kind))) {
            return $this->sendUnauthorizedError('You do not have permission to share from this farm');
        }
        if ($share->status !== FarmShare::STATUS_PENDING) {
            return $this->sendValidationError('This share has already been answered.');
        }
        $share->update(['status' => FarmShare::STATUS_CANCELLED]);

        return $this->sendResponse($this->present($share->load(['sourceFarm', 'targetFarm', 'sharedBy'])), 'Share cancelled');
    }
}
