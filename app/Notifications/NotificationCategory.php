<?php

namespace App\Notifications;

final class NotificationCategory
{
    public const TASKS = 'tasks';
    public const FARM_OPERATIONS = 'farm_operations';
    public const MEDICATION = 'medication';
    public const INVENTORY = 'inventory';
    public const SYSTEM = 'system';
    public const ACCOUNT = 'account';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::TASKS,
            self::FARM_OPERATIONS,
            self::MEDICATION,
            self::INVENTORY,
            self::SYSTEM,
            self::ACCOUNT,
        ];
    }

    public static function label(string $category): string
    {
        return match ($category) {
            self::TASKS => 'Tasks',
            self::FARM_OPERATIONS => 'Farm operations',
            self::MEDICATION => 'Medication',
            self::INVENTORY => 'Inventory',
            self::ACCOUNT => 'Account',
            default => 'System',
        };
    }
}
