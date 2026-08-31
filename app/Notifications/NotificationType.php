<?php

namespace App\Notifications;

/**
 * Canonical notification type identifiers.
 *
 * Adding a type here plus an entry in config/notifications.php is all that is
 * required to make it available to every module and preference screen.
 */
final class NotificationType
{
    // Tasks
    public const TASK_ASSIGNED = 'task_assigned';
    public const TASK_REASSIGNED = 'task_reassigned';
    public const TASK_UPDATED = 'task_updated';
    public const TASK_DUE_SOON = 'task_due_soon';
    public const TASK_DUE_TODAY = 'task_due_today';
    public const TASK_OVERDUE = 'task_overdue';
    public const TASK_ESCALATED = 'task_escalated';
    public const TASK_COMPLETED = 'task_completed';
    public const TASK_AWAITING_APPROVAL = 'task_awaiting_approval';
    public const TASK_APPROVED = 'task_approved';
    public const TASK_REJECTED = 'task_rejected';
    public const TASK_CANCELLED = 'task_cancelled';
    public const TASK_RECURRING_GENERATED = 'task_recurring_generated';

    // Farm operations
    public const FEEDING_REMINDER = 'feeding_reminder';
    public const ANIMAL_HEALTH_ALERT = 'animal_health_alert';
    public const FARM_ACTIVITY_ALERT = 'farm_activity_alert';

    // Medication
    public const MEDICATION_REMINDER = 'medication_reminder';
    public const VACCINATION_REMINDER = 'vaccination_reminder';
    public const MEDICATION_COMPLETED = 'medication_completed';

    // Inventory
    public const INVENTORY_ALERT = 'inventory_alert';
    public const LOW_STOCK_ALERT = 'low_stock_alert';

    // Equipment
    public const EQUIPMENT_MAINTENANCE_DUE = 'equipment_maintenance_due';
    public const EQUIPMENT_WARRANTY_EXPIRING = 'equipment_warranty_expiring';

    // System & account
    public const WELCOME = 'welcome';
    public const ACCOUNT_NOTIFICATION = 'account_notification';
    public const SECURITY_NOTIFICATION = 'security_notification';
    public const SYSTEM_ANNOUNCEMENT = 'system_announcement';
    public const SYSTEM_ALERT = 'system_alert';
}
