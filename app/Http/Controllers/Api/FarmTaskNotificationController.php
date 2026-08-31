<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\Notification;
use App\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

/**
 * Farm-scoped view of task notifications.
 *
 * Kept for the task management screen and older clients; the records now live
 * in the central notification store, so this is a filtered projection of it.
 */
class FarmTaskNotificationController extends ApiController
{
    public function __construct(protected NotificationService $notifications)
    {
    }

    public function index(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('view farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $query = $this->scope($request, $farm)
            ->with('instance:id,title,scheduled_date,status')
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        $limit = min(100, max(1, (int) $request->input('limit', 50)));

        return $this->sendResponse($query->limit($limit)->get(), 'Notifications retrieved');
    }

    public function markRead(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        $notification = $this->scope($request, $farm)->findOrFail($id);
        $this->notifications->markRead($notification);

        return $this->sendResponse($notification->fresh(), 'Notification marked read');
    }

    public function markAllRead(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        $count = $this->notifications->markAllRead(
            $request->user()->id,
            $farm->id,
            NotificationCategory::TASKS,
        );

        return $this->sendResponse(['marked' => $count], 'All notifications marked read');
    }

    protected function scope(Request $request, Farm $farm)
    {
        return Notification::query()
            ->where('farm_id', $farm->id)
            ->forUser($request->user()->id)
            ->visible()
            ->where('category', NotificationCategory::TASKS);
    }
}
