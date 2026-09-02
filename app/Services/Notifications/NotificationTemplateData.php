<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Notifications\NotificationCategory;
use App\Notifications\NotificationTypeRegistry;
use Carbon\CarbonImmutable;

/**
 * Assembles the variable set available to notification email templates.
 *
 * Templates only ever reference these variables, which keeps notification copy
 * out of business logic and lets new types reuse existing layouts.
 */
class NotificationTemplateData
{
    public function __construct(protected NotificationTypeRegistry $registry)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Notification $notification): array
    {
        $notification->loadMissing(['user.settings', 'farm.settings', 'instance.assignee']);

        $farm = $notification->farm;
        $user = $notification->user;
        $timezone = $user?->resolveTimezone($farm) ?? config('app.timezone', 'UTC');

        $payload = $notification->payload ?? [];
        $provided = $payload['template_data'] ?? [];

        $base = [
            'user_name' => $user?->name ?? 'there',
            'farm_name' => $farm?->name ?? config('app.name'),
            'notification_title' => $notification->title,
            'notification_body' => $notification->body,
            'type' => $notification->type,
            'type_label' => $this->registry->label($notification->type),
            'category' => $notification->category,
            'category_label' => NotificationCategory::label($notification->category),
            'priority' => $notification->priority,
            'priority_label' => ucfirst($notification->priority),
            'timestamp' => $this->formatDateTime($notification->created_at, $timezone),
            'timezone' => $timezone,
            'action_url' => $this->absoluteUrl($notification->action_url),
            'action_label' => $notification->action_label ?: 'Open in Farm Central',
            'app_name' => config('app.name'),
            'app_url' => config('notifications.frontend_url'),
        ];

        return array_merge($base, $this->taskVariables($notification, $timezone), $provided);
    }

    /**
     * @return array<string, mixed>
     */
    protected function taskVariables(Notification $notification, string $timezone): array
    {
        $instance = $notification->instance;

        if (!$instance) {
            return [];
        }

        return array_filter([
            'task_name' => $instance->title,
            'task_description' => $instance->description,
            'section' => $instance->section,
            'section_label' => ucfirst(str_replace('_', ' ', (string) $instance->section)),
            'assigned_to' => $instance->assignee?->name,
            'scheduled_date' => $this->formatDate($instance->scheduled_date, $timezone),
            'due_date' => $this->formatDate($instance->scheduled_date, $timezone),
            'start_time' => $this->formatTime($instance->start_time),
            'due_time' => $this->formatTime($instance->due_time),
            'task_priority' => $instance->priority,
            'task_status' => $instance->status,
            'instructions' => $instance->instructions,
            'medication_name' => $instance->medication_name,
            'dosage_instructions' => $instance->dosage_instructions,
            'animal_group' => $instance->animal_group,
            'task_url' => $this->absoluteUrl($notification->action_url),
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function absoluteUrl(?string $path): ?string
    {
        if (blank($path)) {
            return config('notifications.frontend_url');
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return config('notifications.frontend_url') . '/' . ltrim($path, '/');
    }

    protected function formatDateTime(mixed $value, string $timezone): ?string
    {
        if (!$value) {
            return null;
        }

        return CarbonImmutable::parse($value)->setTimezone($timezone)->format('D, d M Y H:i');
    }

    protected function formatDate(mixed $value, string $timezone): ?string
    {
        if (!$value) {
            return null;
        }

        return CarbonImmutable::parse($value)->format('l, F j, Y');
    }

    protected function formatTime(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value)->format('g:i A');
    }
}
