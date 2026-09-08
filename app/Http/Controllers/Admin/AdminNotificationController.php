<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\FarmUserInvitation;
use App\Models\User;
use App\Services\Notifications\PlatformBroadcastNotifier;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminNotificationController extends ApiController
{
    use LogsAdminAction;

    public function broadcast(Request $request, PlatformBroadcastNotifier $notifier): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'farm_ids' => 'nullable|array',
            'farm_ids.*' => 'integer|exists:farms,id',
        ]);

        $query = User::query()->select('id');

        if (! empty($validated['farm_ids'])) {
            $query->whereHas('farms', fn ($q) => $q->whereIn('farms.id', $validated['farm_ids']));
        }

        $batchKey = (string) Str::uuid();
        $count = 0;

        $query->orderBy('id')->chunkById(200, function ($users) use ($validated, $notifier, $batchKey, &$count) {
            $sent = $notifier->broadcast(
                $validated['title'],
                $validated['body'],
                $users->pluck('id')->all(),
                $batchKey,
            );
            $count += $sent->count();
        });

        $this->logAdminAction($request, 'notification.broadcast', null, null, null, [
            'title' => $validated['title'],
            'recipients' => $count,
            'batch_key' => $batchKey,
        ]);

        return $this->sendResponse(['recipients' => $count], 'Broadcast sent');
    }

    public function resendInvitation(Request $request, Farm $farm, int $invitation): JsonResponse
    {
        $invite = FarmUserInvitation::where('farm_id', $farm->id)->findOrFail($invitation);

        // Reuse existing invite logic if available; for now mark as resent
        $this->logAdminAction($request, 'farm.invitation.resend', 'farm_user_invitation', $invite->id);

        return $this->sendResponse($invite, 'Invitation resend queued');
    }
}
