<?php

namespace App\Notifications;

final class NotificationPriority
{
    public const LOW = 'low';
    public const NORMAL = 'normal';
    public const HIGH = 'high';
    public const CRITICAL = 'critical';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::LOW, self::NORMAL, self::HIGH, self::CRITICAL];
    }

    public static function weight(string $priority): int
    {
        return match ($priority) {
            self::CRITICAL => 4,
            self::HIGH => 3,
            self::NORMAL => 2,
            default => 1,
        };
    }

    /**
     * Critical and high notifications are surfaced prominently and always
     * attempt email delivery when the channel is enabled.
     */
    public static function isProminent(string $priority): bool
    {
        return self::weight($priority) >= self::weight(self::HIGH);
    }

    public static function raise(string $priority): string
    {
        return match ($priority) {
            self::LOW => self::NORMAL,
            self::NORMAL => self::HIGH,
            default => self::CRITICAL,
        };
    }
}
