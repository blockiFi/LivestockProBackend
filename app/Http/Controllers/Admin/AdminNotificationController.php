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
            'body' => $validated['body'],
            'farm_ids' => $validated['farm_ids'] ?? [],
            'recipients' => $count,
            'batch_key' => $batchKey,
        ]);

        return $this->sendResponse(['recipients' => $count], 'Broadcast sent');
    }

    /** Past broadcasts, read from the audit log entries each send writes. */
    public function history(Request $request): JsonResponse
    {
        $page = \App\Models\AdminAuditLog::with('adminUser:id,name,email')
            ->where('action', 'notification.broadcast')
            ->orderByDesc('created_at')
            ->paginate(min(50, max(5, $request->integer('per_page', 20))));

        return $this->sendResponse($page, 'Broadcast history retrieved');
    }

    public function invitations(Farm $farm): JsonResponse
    {
        return $this->sendResponse(
            FarmUserInvitation::where('farm_id', $farm->id)->latest()->limit(100)->get(),
            'Invitations retrieved'
        );
    }

    /** Gives a pending invitation another 7 days, as the farm app's resend does. */
    public function resendInvitation(Request $request, Farm $farm, int $invitation): JsonResponse
    {
        $invite = FarmUserInvitation::where('farm_id', $farm->id)->findOrFail($invitation);

        if ($invite->status !== 'pending') {
            return $this->sendError('Only pending invitations can be resent', [], 422);
        }

        $invite->expires_at = now()->addDays(7);
        $invite->save();

        $this->logAdminAction($request, 'farm.invitation.resend', 'farm', $farm->id, null, ['invitation_id' => $invite->id, 'email' => $invite->email]);

        return $this->sendResponse($invite, 'Invitation extended for 7 days');
    }
}
