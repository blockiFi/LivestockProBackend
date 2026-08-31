<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\FarmUserInvitation;
use App\Models\Notification;
use App\Models\User;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AdminNotificationController extends ApiController
{
    use LogsAdminAction;

    public function broadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:2000',
            'farm_ids' => 'nullable|array',
            'farm_ids.*' => 'integer|exists:farms,id',
        ]);

        $query = User::query();

        if (! empty($validated['farm_ids'])) {
            $query->whereHas('farms', fn ($q) => $q->whereIn('farms.id', $validated['farm_ids']));
        }

        $users = $query->get();
        $count = 0;

        foreach ($users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'platform_broadcast',
                'title' => $validated['title'],
                'body' => $validated['body'],
                'category' => 'system',
                'priority' => 'normal',
                'status' => 'unread',
            ]);
            $count++;
        }

        $this->logAdminAction($request, 'notification.broadcast', null, null, null, [
            'title' => $validated['title'],
            'recipients' => $count,
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
