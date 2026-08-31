<?php

namespace App\Notifications;

final class DeliveryStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const QUEUED = 'queued';
    public const SENT = 'sent';
    public const DELIVERED = 'delivered';
    public const READ = 'read';
    public const FAILED = 'failed';
    public const RETRYING = 'retrying';
    public const CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::QUEUED,
            self::SENT,
            self::DELIVERED,
            self::READ,
            self::FAILED,
            self::RETRYING,
            self::CANCELLED,
        ];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::DELIVERED, self::READ, self::FAILED, self::CANCELLED], true);
    }
}
