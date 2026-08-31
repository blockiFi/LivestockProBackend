<?php

namespace App\Notifications;

use Illuminate\Support\Arr;

/**
 * Read-only view over config/notifications.php.
 *
 * Everything that needs to know about a notification type (category, default
 * priority, default channels, whether a user may opt out, which email template
 * to render) resolves it here so the catalog stays the single source of truth.
 */
class NotificationTypeRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $types = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->types === null) {
            $this->types = collect(config('notifications.types', []))
                ->map(fn (array $definition, string $type) => $this->normalize($type, $definition))
                ->all();
        }

        return $this->types;
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $type): array
    {
        return $this->all()[$type] ?? $this->normalize($type, []);
    }

    /**
     * @return list<string>
     */
    public function typesForCategory(string $category): array
    {
        return collect($this->all())
            ->filter(fn (array $definition) => $definition['category'] === $category)
            ->keys()
            ->all();
    }

    public function category(string $type): string
    {
        return $this->get($type)['category'];
    }

    public function defaultPriority(string $type): string
    {
        return $this->get($type)['priority'];
    }

    /**
     * @return list<string>
     */
    public function defaultChannels(string $type): array
    {
        return $this->get($type)['channels'];
    }

    public function template(string $type): string
    {
        return $this->get($type)['template'];
    }

    public function label(string $type): string
    {
        return $this->get($type)['label'];
    }

    public function isMandatory(string $type): bool
    {
        return $this->get($type)['mandatory'];
    }

    /**
     * Channels the user may never switch off for this type. Mandatory types
     * normally keep the in-app record locked while still allowing the email
     * channel to be silenced.
     *
     * @return list<string>
     */
    public function lockedChannels(string $type): array
    {
        return $this->get($type)['locked'];
    }

    public function userCanToggle(string $type, string $channel): bool
    {
        return !in_array($channel, $this->lockedChannels($type), true);
    }

    public function supportsChannel(string $type, string $channel): bool
    {
        return in_array($channel, $this->get($type)['supported'], true);
    }

    /**
     * Catalog shaped for the preferences UI, grouped by category.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        $grouped = [];

        foreach ($this->all() as $type => $definition) {
            $grouped[$definition['category']][] = [
                'type' => $type,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'priority' => $definition['priority'],
                'default_channels' => $definition['channels'],
                'mandatory' => $definition['mandatory'],
                'locked_channels' => $definition['locked'],
                'supported_channels' => $definition['supported'],
            ];
        }

        return collect(NotificationCategory::all())
            ->filter(fn (string $category) => isset($grouped[$category]))
            ->map(fn (string $category) => [
                'category' => $category,
                'label' => NotificationCategory::label($category),
                'types' => $grouped[$category],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    protected function normalize(string $type, array $definition): array
    {
        $channels = array_values(array_intersect(
            NotificationChannel::all(),
            Arr::get($definition, 'channels', [NotificationChannel::IN_APP])
        ));

        $mandatory = (bool) Arr::get($definition, 'mandatory', false);
        $locked = array_values(array_intersect(
            NotificationChannel::all(),
            Arr::get($definition, 'locked', $mandatory ? [NotificationChannel::IN_APP] : [])
        ));

        return [
            'type' => $type,
            'label' => Arr::get($definition, 'label', str_replace('_', ' ', ucfirst($type))),
            'description' => Arr::get($definition, 'description', ''),
            'category' => Arr::get($definition, 'category', config('notifications.defaults.category')),
            'priority' => Arr::get($definition, 'priority', config('notifications.defaults.priority')),
            'channels' => $channels,
            'supported' => NotificationChannel::all(),
            'mandatory' => $mandatory,
            'locked' => $locked,
            'template' => Arr::get($definition, 'template', config('notifications.defaults.template')),
        ];
    }
}
