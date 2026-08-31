<?php

namespace App\Notifications;

final class NotificationChannel
{
    public const IN_APP = 'in_app';
    public const EMAIL = 'email';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::IN_APP, self::EMAIL];
    }
}
