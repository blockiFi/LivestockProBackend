<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use App\Notifications\NotificationCategory;
use App\Notifications\NotificationPriority;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Notification centre for the signed-in user.
 *
 * Deliberately not farm-scoped: the bell and centre show everything addressed
 * to the user, optionally narrowed to the active farm.
 */
class NotificationController extends ApiController
{
    public function __construct(protected NotificationService $notifications)
    {
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'nullable|integer',
            'category' => 'nullable|string|in:' . implode(',', NotificationCategory::all()),
            'priority' => 'nullable|string|in:' . implode(',', NotificationPriority::all()),
            'type' => 'nullable|string|max:64',
            // Query strings send "true"/"false"; Laravel's boolean rule only
            // accepts 0/1. $request->boolean() already understands both.
            'unread_only' => 'nullable',
            'search' => 'nullable|string|max:191',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
            'paginate' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $query = Notification::query()
            ->forUser($request->user()->id)
            ->visible()
            ->with([
                'instance:id,title,scheduled_date,status,section,priority',
                'farm:id,name',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('farm_id')) {
            $query->forFarmContext((int) $request->farm_id);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->boolean('unread_only')) {
            $query->unread();
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('paginate')) {
            $perPage = (int) $request->input('per_page', 20);
            $page = $query->paginate($perPage);

            return $this->sendResponse([
                'data' => $page->items(),
                'current_page' => $page->currentPage(),
                'total_pages' => $page->lastPage(),
                'total_records' => $page->total(),
            ], 'Notifications retrieved');
        }

        $limit = (int) $request->input('limit', 30);

        return $this->sendResponse($query->limit($limit)->get(), 'Notifications retrieved');
    }

    /**
     * Badge counts for the bell plus per-tab counts for the centre.
     */
    public function summary(Request $request)
    {
        $userId = $request->user()->id;
        $farmId = $request->filled('farm_id') ? (int) $request->farm_id : null;

        $base = Notification::query()->forUser($userId)->visible()
            ->when($farmId, fn ($q) => $q->forFarmContext($farmId));

        $byCategory = (clone $base)->unread()
            ->selectRaw('category, COUNT(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');

        $categories = [];
        foreach (NotificationCategory::all() as $category) {
            $categories[$category] = (int) ($byCategory[$category] ?? 0);
        }

        return $this->sendResponse([
            'unread' => (clone $base)->unread()->count(),
            'total' => (clone $base)->count(),
            'high_priority_unread' => (clone $base)->unread()->prominent()->count(),
            'categories' => $categories,
            'latest' => (clone $base)->unread()
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'type', 'category', 'priority', 'title', 'body', 'action_url', 'created_at']),
        ], 'Notification summary retrieved');
    }

    public function show(Request $request, $id)
    {
        $notification = Notification::query()
            ->forUser($request->user()->id)
            ->with([
                'instance.assignee:id,name',
                'farm:id,name',
                'deliveries',
            ])
            ->findOrFail($id);

        return $this->sendResponse($notification, 'Notification retrieved');
    }

    public function markRead(Request $request, $id)
    {
        $notification = Notification::query()
            ->forUser($request->user()->id)
            ->findOrFail($id);

        $this->notifications->markRead($notification);

        return $this->sendResponse($notification->fresh(), 'Notification marked read');
    }

    public function markUnread(Request $request, $id)
    {
        $notification = Notification::query()
            ->forUser($request->user()->id)
            ->findOrFail($id);

        $notification->forceFill(['read_at' => null, 'status' => 'delivered'])->save();

        return $this->sendResponse($notification->fresh(), 'Notification marked unread');
    }

    public function markAllRead(Request $request)
    {
        $count = $this->notifications->markAllRead(
            $request->user()->id,
            $request->filled('farm_id') ? (int) $request->farm_id : null,
            $request->filled('category') ? $request->category : null,
        );

        return $this->sendResponse(['marked' => $count], 'Notifications marked read');
    }

    public function destroy(Request $request, $id)
    {
        $notification = Notification::query()
            ->forUser($request->user()->id)
            ->findOrFail($id);

        // Dismissed rather than deleted so history and analytics stay intact.
        $notification->forceFill([
            'dismissed_at' => now(),
            'read_at' => $notification->read_at ?? now(),
        ])->save();

        return $this->sendResponse(null, 'Notification dismissed');
    }

    public function destroyAll(Request $request)
    {
        $query = Notification::query()
            ->forUser($request->user()->id)
            ->whereNull('dismissed_at')
            ->when($request->filled('farm_id'), fn ($q) => $q->forFarmContext((int) $request->farm_id))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category));

        if ($request->boolean('read_only')) {
            $query->whereNotNull('read_at');
        }

        $count = $query->update([
            'dismissed_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->sendResponse(['dismissed' => $count], 'Notifications dismissed');
    }
}
