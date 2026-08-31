<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\FarmNotificationSetting;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\ScheduledNotification;
use App\Notifications\DeliveryStatus;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationPriority;
use App\Notifications\NotificationType;
use App\Notifications\NotificationTypeRegistry;
use App\Services\Notifications\TaskReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

/**
 * Farm administrator controls: which notification types are active, their
 * default channels, mandatory flags, reminder defaults and escalation timings.
 */
class FarmNotificationSettingController extends ApiController
{
    public function __construct(
        protected NotificationTypeRegistry $registry,
        protected TaskReminderService $reminders,
    ) {
    }

    public function index(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('view farm')) {
            return $this->sendUnauthorizedError('You do not have permission to view farm settings');
        }

        return $this->sendResponse([
            'catalog' => $this->registry->catalog(),
            'types' => $this->typeSettings($farm),
            'config' => $farm->notificationConfigOrDefault(),
            'reminder_presets' => config('notifications.reminders.presets'),
        ], 'Farm notification settings retrieved');
    }

    public function update(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('manage farm settings')) {
            return $this->sendUnauthorizedError('You do not have permission to manage farm settings');
        }

        $validator = Validator::make($request->all(), [
            'types' => 'nullable|array',
            'types.*.type' => 'required|string|max:64',
            'types.*.enabled' => 'nullable|boolean',
            'types.*.mandatory' => 'nullable|boolean',
            'types.*.default_in_app' => 'nullable|boolean',
            'types.*.default_email' => 'nullable|boolean',
            'types.*.priority' => 'nullable|string|in:' . implode(',', NotificationPriority::all()),
            'config' => 'nullable|array',
            'config.default_task_reminders' => 'nullable|array|max:5',
            'config.default_task_reminders.*' => 'integer|min:0|max:10080',
            'config.escalation_enabled' => 'nullable|boolean',
            'config.escalate_to_manager_after_minutes' => 'nullable|integer|min:5|max:10080',
            'config.escalate_high_priority_after_minutes' => 'nullable|integer|min:5|max:10080',
            'config.notify_managers_on_completion' => 'nullable|boolean',
            'config.notify_managers_on_overdue' => 'nullable|boolean',
            'config.email_max_attempts' => 'nullable|integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $unknown = [];

        foreach ($request->input('types', []) as $row) {
            $type = $row['type'];

            if (!$this->registry->has($type)) {
                $unknown[] = $type;
                continue;
            }

            $locked = $this->registry->lockedChannels($type);

            FarmNotificationSetting::updateOrCreate(
                ['farm_id' => $farm->id, 'type' => $type],
                [
                    // Catalog-mandatory types cannot be switched off by admins.
                    'enabled' => $this->registry->isMandatory($type)
                        ? true
                        : (bool) ($row['enabled'] ?? true),
                    'mandatory' => (bool) ($row['mandatory'] ?? $this->registry->isMandatory($type)),
                    'default_in_app' => in_array(NotificationChannel::IN_APP, $locked, true)
                        ? true
                        : (bool) ($row['default_in_app'] ?? true),
                    'default_email' => in_array(NotificationChannel::EMAIL, $locked, true)
                        ? true
                        : (bool) ($row['default_email'] ?? true),
                    'priority' => $row['priority'] ?? null,
                ]
            );
        }

        if ($request->filled('config')) {
            $config = $farm->notificationConfigOrDefault();
            $payload = $request->input('config');

            if (array_key_exists('default_task_reminders', $payload)) {
                $payload['default_task_reminders'] = collect($this->reminders->normalizeReminders($payload['default_task_reminders'] ?? []))
                    ->pluck('offset_minutes')
                    ->all();
            }

            $config->fill($payload)->save();
        }

        return $this->sendResponse([
            'types' => $this->typeSettings($farm),
            'config' => $farm->notificationConfigOrDefault(),
            'unknown_types' => $unknown,
        ], 'Farm notification settings updated');
    }

    /**
     * Delivery health metrics so administrators can spot problems.
     */
    public function analytics(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('view farm')) {
            return $this->sendUnauthorizedError('You do not have permission to view farm analytics');
        }

        $days = min(90, max(1, (int) $request->input('days', 30)));
        $since = CarbonImmutable::now()->subDays($days)->startOfDay();

        $notifications = Notification::query()
            ->where('farm_id', $farm->id)
            ->where('created_at', '>=', $since);

        $deliveries = NotificationDelivery::query()
            ->whereIn('notification_id', Notification::query()
                ->where('farm_id', $farm->id)
                ->where('created_at', '>=', $since)
                ->select('id'));

        $emailStatuses = (clone $deliveries)
            ->where('channel', NotificationChannel::EMAIL)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byCategory = (clone $notifications)
            ->selectRaw('category, COUNT(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');

        $byType = (clone $notifications)
            ->selectRaw('type, COUNT(*) as aggregate')
            ->groupBy('type')
            ->orderByDesc('aggregate')
            ->limit(10)
            ->pluck('aggregate', 'type');

        $sent = (clone $notifications)->count();
        $read = (clone $notifications)->whereNotNull('read_at')->count();

        return $this->sendResponse([
            'window_days' => $days,
            'notifications_sent' => $sent,
            'notifications_read' => $read,
            'notifications_unread' => max(0, $sent - $read),
            'read_rate' => $sent > 0 ? round(($read / $sent) * 100, 1) : 0.0,
            'email_queued' => (int) ($emailStatuses[DeliveryStatus::QUEUED] ?? 0) + (int) ($emailStatuses[DeliveryStatus::PENDING] ?? 0),
            'email_sent' => (int) ($emailStatuses[DeliveryStatus::SENT] ?? 0),
            'email_retrying' => (int) ($emailStatuses[DeliveryStatus::RETRYING] ?? 0),
            'email_failed' => (int) ($emailStatuses[DeliveryStatus::FAILED] ?? 0),
            'email_cancelled' => (int) ($emailStatuses[DeliveryStatus::CANCELLED] ?? 0),
            'task_reminders_sent' => (clone $notifications)
                ->whereIn('type', [NotificationType::TASK_DUE_SOON, NotificationType::TASK_DUE_TODAY])
                ->count(),
            'overdue_alerts' => (clone $notifications)
                ->whereIn('type', [NotificationType::TASK_OVERDUE, NotificationType::TASK_ESCALATED])
                ->count(),
            'reminders_pending' => ScheduledNotification::query()
                ->where('farm_id', $farm->id)
                ->where('status', ScheduledNotification::STATUS_PENDING)
                ->count(),
            'by_category' => $byCategory,
            'top_types' => $byType,
            'recent_failures' => (clone $deliveries)
                ->where('status', DeliveryStatus::FAILED)
                ->latest('failed_at')
                ->limit(10)
                ->get(['id', 'notification_id', 'channel', 'target', 'attempts', 'error', 'failed_at']),
        ], 'Notification analytics retrieved');
    }

    /**
     * Registry defaults merged with any farm overrides.
     */
    protected function typeSettings(Farm $farm): array
    {
        $overrides = FarmNotificationSetting::where('farm_id', $farm->id)->get()->keyBy('type');

        return collect($this->registry->all())
            ->map(function (array $definition, string $type) use ($overrides) {
                $override = $overrides->get($type);

                return [
                    'type' => $type,
                    'label' => $definition['label'],
                    'category' => $definition['category'],
                    'enabled' => $override?->enabled ?? true,
                    'mandatory' => $override?->mandatory ?? $definition['mandatory'],
                    'default_in_app' => $override?->default_in_app
                        ?? in_array(NotificationChannel::IN_APP, $definition['channels'], true),
                    'default_email' => $override?->default_email
                        ?? in_array(NotificationChannel::EMAIL, $definition['channels'], true),
                    'priority' => $override?->priority ?? $definition['priority'],
                    'locked_channels' => $definition['locked'],
                    'catalog_mandatory' => $definition['mandatory'],
                ];
            })
            ->values()
            ->all();
    }
}
