<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPriority;
use App\Notifications\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Platform-wide announcements from Farm Central admins (in-app + email).
 */
class PlatformBroadcastNotifier
{
    public function __construct(protected NotificationService $notifications)
    {
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, Notification>
     */
    public function broadcast(string $title, string $body, array $userIds, ?string $batchKey = null): Collection
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $userIds = array_values(array_filter($userIds, fn (int $id) => $id > 0));

        if ($userIds === []) {
            return collect();
        }

        $batchKey ??= (string) Str::uuid();

        return $this->notifications->send(
            NotificationMessage::make(NotificationType::PLATFORM_BROADCAST)
                ->to(...$userIds)
                ->priority(NotificationPriority::NORMAL)
                ->title($title)
                ->body($body)
                ->action('/dashboard/notifications', 'View announcements')
                ->dedupe('platform_broadcast:' . $batchKey)
                ->with(['broadcast_batch' => $batchKey])
        );
    }
}
