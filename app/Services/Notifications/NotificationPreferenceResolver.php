<?php

namespace App\Services\Notifications;

use App\Models\FarmNotificationSetting;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationTypeRegistry;

/**
 * Resolves which channels a given notification type should use for a user,
 * combining registry defaults, farm administrator policy and user choices.
 *
 * Precedence (lowest to highest):
 *   1. Registry defaults from config/notifications.php
 *   2. Farm administrator defaults (farm_notification_settings)
 *   3. User preference rows (farm specific beats global)
 *   4. User master email switch
 *   5. Locked/mandatory channels, which always win
 */
class NotificationPreferenceResolver
{
    /** @var array<string, array<string, FarmNotificationSetting>> */
    protected array $farmSettingCache = [];

    /** @var array<string, array<string, NotificationPreference>> */
    protected array $userPreferenceCache = [];

    public function __construct(protected NotificationTypeRegistry $registry)
    {
    }

    /**
     * Whether the farm administrator has switched this type off entirely.
     */
    public function isTypeEnabledForFarm(?int $farmId, string $type): bool
    {
        $setting = $this->farmSetting($farmId, $type);

        return $setting === null || $setting->enabled;
    }

    public function priorityForFarm(?int $farmId, string $type, ?string $requested): string
    {
        $setting = $this->farmSetting($farmId, $type);

        return $requested
            ?? $setting?->priority
            ?? $this->registry->defaultPriority($type);
    }

    /**
     * Channels that should actually be used for this user.
     *
     * @return list<string>
     */
    public function channelsFor(User $user, ?int $farmId, string $type): array
    {
        $registryDefaults = $this->registry->defaultChannels($type);
        $locked = $this->registry->lockedChannels($type);
        $farmSetting = $this->farmSetting($farmId, $type);

        $enabled = [];

        foreach (NotificationChannel::all() as $channel) {
            // Types only advertise the channels they make sense on.
            if (!in_array($channel, $registryDefaults, true) && !in_array($channel, $locked, true)) {
                continue;
            }

            $value = in_array($channel, $registryDefaults, true);

            if ($farmSetting) {
                $value = $channel === NotificationChannel::EMAIL
                    ? $farmSetting->default_email
                    : $farmSetting->default_in_app;
            }

            $preference = $this->userPreference($user->id, $farmId, $type);
            if ($preference) {
                $value = $channel === NotificationChannel::EMAIL
                    ? $preference->email
                    : $preference->in_app;
            }

            if ($channel === NotificationChannel::EMAIL) {
                // The master switch defaults to on when no settings row exists.
                $settings = $user->notificationSettings;
                if (($settings !== null && !$settings->email_enabled) || blank($user->email)) {
                    $value = false;
                }
            }

            // Mandatory channels cannot be silenced by users or admins.
            if (in_array($channel, $locked, true) || ($farmSetting?->mandatory && $channel === NotificationChannel::IN_APP)) {
                $value = true;
            }

            if ($value) {
                $enabled[] = $channel;
            }
        }

        return $enabled;
    }

    /**
     * Effective preference matrix for the preferences UI.
     *
     * @return array<string, array{in_app: bool, email: bool, locked: list<string>, mandatory: bool}>
     */
    public function matrixFor(User $user, ?int $farmId): array
    {
        $matrix = [];

        foreach (array_keys($this->registry->all()) as $type) {
            $channels = $this->channelsFor($user, $farmId, $type);
            $farmSetting = $this->farmSetting($farmId, $type);
            $locked = $this->registry->lockedChannels($type);

            if ($farmSetting?->mandatory && !in_array(NotificationChannel::IN_APP, $locked, true)) {
                $locked[] = NotificationChannel::IN_APP;
            }

            $matrix[$type] = [
                'in_app' => in_array(NotificationChannel::IN_APP, $channels, true),
                'email' => in_array(NotificationChannel::EMAIL, $channels, true),
                'locked' => array_values($locked),
                'mandatory' => $this->registry->isMandatory($type) || (bool) $farmSetting?->mandatory,
                'enabled_by_admin' => $farmSetting === null || $farmSetting->enabled,
            ];
        }

        return $matrix;
    }

    public function forget(): void
    {
        $this->farmSettingCache = [];
        $this->userPreferenceCache = [];
    }

    protected function farmSetting(?int $farmId, string $type): ?FarmNotificationSetting
    {
        if ($farmId === null) {
            return null;
        }

        $key = (string) $farmId;

        if (!isset($this->farmSettingCache[$key])) {
            $this->farmSettingCache[$key] = FarmNotificationSetting::query()
                ->where('farm_id', $farmId)
                ->get()
                ->keyBy('type')
                ->all();
        }

        return $this->farmSettingCache[$key][$type] ?? null;
    }

    protected function userPreference(int $userId, ?int $farmId, string $type): ?NotificationPreference
    {
        $key = $userId . ':' . ($farmId ?? 'global');

        if (!isset($this->userPreferenceCache[$key])) {
            $this->userPreferenceCache[$key] = NotificationPreference::query()
                ->where('user_id', $userId)
                ->where(function ($query) use ($farmId) {
                    $query->whereNull('farm_id');
                    if ($farmId !== null) {
                        $query->orWhere('farm_id', $farmId);
                    }
                })
                // Global rows first so farm specific rows overwrite them on keyBy.
                ->orderByRaw('farm_id IS NULL DESC')
                ->get()
                ->keyBy('type')
                ->all();
        }

        return $this->userPreferenceCache[$key][$type] ?? null;
    }
}
