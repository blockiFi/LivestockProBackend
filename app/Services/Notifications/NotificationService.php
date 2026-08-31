<?php

namespace App\Services\Notifications;

use App\Jobs\SendNotificationEmail;
use App\Models\Farm;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\DeliveryStatus;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPriority;
use App\Notifications\NotificationTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single entry point every module uses to notify people.
 *
 * Responsibilities: resolve recipients, apply farm policy and user preferences,
 * guarantee de-duplication, persist the in-app record and queue email delivery.
 * Callers never touch channels, templates or the queue directly.
 */
class NotificationService
{
    public function __construct(
        protected NotificationTypeRegistry $registry,
        protected NotificationPreferenceResolver $preferences,
        protected RecipientResolver $recipients,
    ) {
    }

    /**
     * @return Collection<int, Notification>
     */
    public function send(NotificationMessage $message): Collection
    {
        if (!$this->registry->has($message->type)) {
            Log::warning('Unknown notification type dispatched', ['type' => $message->type]);
        }

        if (!$this->preferences->isTypeEnabledForFarm($message->farmId, $message->type)) {
            return collect();
        }

        $recipients = $this->recipients->resolve(
            $message->farmId,
            $message->userIds,
            $message->permissions,
            $message->excludeUserIds,
        );

        if ($recipients->isEmpty()) {
            return collect();
        }

        $farm = $message->farmId ? Farm::with('settings')->find($message->farmId) : null;
        $priority = $this->preferences->priorityForFarm($message->farmId, $message->type, $message->priority);
        $category = $this->registry->category($message->type);

        $created = collect();

        foreach ($recipients as $recipient) {
            $channels = $this->preferences->channelsFor($recipient, $message->farmId, $message->type);

            if ($channels === []) {
                continue;
            }

            $notification = $this->persist($message, $recipient, $farm, $category, $priority, $channels);

            if ($notification !== null) {
                $created->push($notification);
            }
        }

        return $created;
    }

    /**
     * Convenience wrapper for a single recipient.
     */
    public function sendTo(int|User $user, NotificationMessage $message): ?Notification
    {
        return $this->send($message->to($user))->first();
    }

    /**
     * @param  list<string>  $channels
     */
    protected function persist(
        NotificationMessage $message,
        User $recipient,
        ?Farm $farm,
        string $category,
        string $priority,
        array $channels,
    ): ?Notification {
        $dedupeKey = $this->dedupeKey($message, $recipient);

        $attributes = [
            'farm_id' => $message->farmId,
            'user_id' => $recipient->id,
            'type' => $message->type,
            'category' => $category,
            'priority' => $priority,
            'title' => $message->title ?: $this->registry->label($message->type),
            'body' => $message->body,
            'action_url' => $message->actionUrl,
            'action_label' => $message->actionLabel,
            'source_type' => $message->sourceType,
            'source_id' => $message->sourceId,
            'instance_id' => $message->instanceId,
            'section' => $message->section,
            'payload' => $this->buildPayload($message, $recipient, $farm),
            'status' => DeliveryStatus::PROCESSING,
            'available_at' => $message->availableAt ?? now(),
        ];

        try {
            $notification = Notification::firstOrCreate(['dedupe_key' => $dedupeKey], $attributes);
        } catch (QueryException $e) {
            // Concurrent writer won the race; the existing row is authoritative.
            $notification = Notification::where('dedupe_key', $dedupeKey)->first();

            if (!$notification) {
                Log::error('Failed to persist notification', [
                    'type' => $message->type,
                    'user_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        if (!$notification->wasRecentlyCreated) {
            return null;
        }

        $this->recordInAppDelivery($notification, $channels);

        if (in_array(NotificationChannel::EMAIL, $channels, true)) {
            $this->queueEmail($notification, $recipient, $farm);
        }

        return $notification;
    }

    /**
     * @param  list<string>  $channels
     */
    protected function recordInAppDelivery(Notification $notification, array $channels): void
    {
        if (!in_array(NotificationChannel::IN_APP, $channels, true)) {
            // Email-only notifications still need a row users can read later,
            // so keep the record but mark the in-app channel as cancelled.
            NotificationDelivery::create([
                'notification_id' => $notification->id,
                'channel' => NotificationChannel::IN_APP,
                'status' => DeliveryStatus::CANCELLED,
                'max_attempts' => 1,
            ]);

            return;
        }

        NotificationDelivery::create([
            'notification_id' => $notification->id,
            'channel' => NotificationChannel::IN_APP,
            'status' => DeliveryStatus::DELIVERED,
            'attempts' => 1,
            'max_attempts' => 1,
            'queued_at' => now(),
            'sent_at' => now(),
            'delivered_at' => now(),
        ]);

        $notification->forceFill(['status' => DeliveryStatus::DELIVERED])->save();
    }

    protected function queueEmail(Notification $notification, User $recipient, ?Farm $farm): void
    {
        $maxAttempts = $farm
            ? (int) ($farm->notificationConfig?->email_max_attempts ?: config('notifications.email.max_attempts'))
            : (int) config('notifications.email.max_attempts');

        $delivery = NotificationDelivery::create([
            'notification_id' => $notification->id,
            'channel' => NotificationChannel::EMAIL,
            'status' => DeliveryStatus::PENDING,
            'max_attempts' => max(1, $maxAttempts),
            'target' => $recipient->email,
        ]);

        $delay = $this->quietHoursDelay($recipient, $farm, $notification->priority);

        $delivery->markQueued();

        $job = SendNotificationEmail::dispatch($delivery->id)
            ->onQueue(config('notifications.queue'));

        if ($delay !== null) {
            $job->delay($delay);
        }
    }

    /**
     * Critical notifications ignore quiet hours; everything else waits for the
     * window to close so we do not email people in the middle of the night.
     */
    protected function quietHoursDelay(User $recipient, ?Farm $farm, string $priority): ?CarbonImmutable
    {
        if ($priority === NotificationPriority::CRITICAL) {
            return null;
        }

        $settings = $recipient->notificationSettings;
        if (!$settings || !$settings->quiet_hours_start || !$settings->quiet_hours_end) {
            return null;
        }

        $timezone = $recipient->resolveTimezone($farm);
        $now = CarbonImmutable::now($timezone);
        $start = CarbonImmutable::parse($now->toDateString() . ' ' . $settings->quiet_hours_start, $timezone);
        $end = CarbonImmutable::parse($now->toDateString() . ' ' . $settings->quiet_hours_end, $timezone);

        // Windows such as 22:00 -> 06:00 wrap past midnight.
        if ($end->lessThanOrEqualTo($start)) {
            if ($now->greaterThanOrEqualTo($start)) {
                return $end->addDay()->utc();
            }

            if ($now->lessThan($end)) {
                return $end->utc();
            }

            return null;
        }

        if ($now->betweenIncluded($start, $end)) {
            return $end->utc();
        }

        return null;
    }

    protected function buildPayload(NotificationMessage $message, User $recipient, ?Farm $farm): array
    {
        return array_merge($message->payload, [
            'template_data' => array_merge([
                'user_name' => $recipient->name,
                'farm_name' => $farm?->name,
            ], $message->templateData),
        ]);
    }

    protected function dedupeKey(NotificationMessage $message, User $recipient): string
    {
        $event = $message->dedupeKey ?: $message->type . ':' . Str::uuid()->toString();

        return Str::limit($event . ':u' . $recipient->id, 190, '');
    }

    /**
     * Marks a notification read on behalf of its owner.
     */
    public function markRead(Notification $notification): bool
    {
        return $notification->markRead();
    }

    public function markAllRead(int $userId, ?int $farmId = null, ?string $category = null): int
    {
        $query = Notification::query()
            ->forUser($userId)
            ->unread()
            ->when($farmId, fn ($q) => $q->forFarmContext($farmId))
            ->when($category, fn ($q) => $q->where('category', $category));

        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        Notification::whereIn('id', $ids)->update([
            'read_at' => now(),
            'status' => DeliveryStatus::READ,
            'updated_at' => now(),
        ]);

        NotificationDelivery::whereIn('notification_id', $ids)
            ->where('channel', NotificationChannel::IN_APP)
            ->update(['status' => DeliveryStatus::READ, 'updated_at' => now()]);

        return $ids->count();
    }

    public function unreadCount(int $userId, ?int $farmId = null): int
    {
        return Notification::query()
            ->forUser($userId)
            ->visible()
            ->unread()
            ->when($farmId, fn ($q) => $q->forFarmContext($farmId))
            ->count();
    }
}
