<?php

use App\Notifications\NotificationCategory;
use App\Notifications\NotificationPriority;
use App\Notifications\NotificationType;

/*
|--------------------------------------------------------------------------
| Notification platform catalog
|--------------------------------------------------------------------------
|
| Every notification emitted by the application is declared here. Adding a
| new notification type is a matter of adding an entry to `types` and (if the
| default email layout is not enough) a matching blade template under
| resources/views/emails/notifications.
|
| Keys per type:
|   label            Human readable name shown in preference screens.
|   category         Groups the type for notification-center tabs.
|   priority         Default priority when the emitter does not override it.
|   channels         Channels enabled by default for new users.
|   mandatory        Users cannot switch the type off entirely (admins may
|                    also mark additional types mandatory per farm).
|   locked           Channels the user is never allowed to disable. Defaults to
|                    ['in_app'] for mandatory types so a record always exists.
|   template         Blade view used for the email body.
|   description      Copy shown in the preferences UI.
|
*/

return [

    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),

    // Base URL of the SPA, used to turn relative action links into absolute
    // ones for emails.
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    'email' => [
        'max_attempts' => (int) env('NOTIFICATIONS_EMAIL_MAX_ATTEMPTS', 3),
        // Minutes to wait before each retry (index = attempt number - 1).
        'retry_backoff_minutes' => [1, 5, 15],
    ],

    'reminders' => [
        // Offsets (minutes before the task start/due time) offered in the UI.
        'presets' => [0, 5, 15, 30, 60, 120, 1440],
        'max_per_task' => 5,
        // How far ahead the engine materialises reminder occurrences.
        'horizon_days' => (int) env('NOTIFICATIONS_REMINDER_HORIZON_DAYS', 14),
        // Reminders older than this are skipped rather than fired late.
        'stale_after_minutes' => 120,
    ],

    'escalation' => [
        'enabled' => true,
        'notify_manager_after_minutes' => 60,
        'raise_priority_after_minutes' => 180,
    ],

    'retention_days' => (int) env('NOTIFICATIONS_RETENTION_DAYS', 180),

    'defaults' => [
        'category' => NotificationCategory::SYSTEM,
        'priority' => NotificationPriority::NORMAL,
        'template' => 'emails.notifications.generic',
    ],

    'types' => [

        /*
        |----------------------------------------------------------------------
        | Tasks
        |----------------------------------------------------------------------
        */
        NotificationType::TASK_ASSIGNED => [
            'label' => 'Task assigned',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.task_assigned',
            'description' => 'A farm task has been assigned to you.',
        ],
        NotificationType::TASK_REASSIGNED => [
            'label' => 'Task reassigned',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.task_reassigned',
            'description' => 'A task was moved to or away from you.',
        ],
        NotificationType::TASK_UPDATED => [
            'label' => 'Task updated',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::LOW,
            'channels' => ['in_app'],
            'template' => 'emails.notifications.task_updated',
            'description' => 'Details of a task assigned to you changed.',
        ],
        NotificationType::TASK_DUE_SOON => [
            'label' => 'Task reminder',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.task_reminder',
            'description' => 'Reminders sent ahead of a task start time.',
        ],
        NotificationType::TASK_DUE_TODAY => [
            'label' => 'Task due today',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.task_reminder',
            'description' => 'A daily summary style alert when a task is due today.',
        ],
        NotificationType::TASK_OVERDUE => [
            'label' => 'Task overdue',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::HIGH,
            'channels' => ['in_app', 'email'],
            'mandatory' => true,
            'locked' => ['in_app'],
            'template' => 'emails.notifications.task_overdue',
            'description' => 'A task passed its due time without being completed.',
        ],
        NotificationType::TASK_ESCALATED => [
            'label' => 'Overdue task escalated',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::CRITICAL,
            'channels' => ['in_app', 'email'],
            'mandatory' => true,
            'locked' => ['in_app'],
            'template' => 'emails.notifications.task_overdue',
            'description' => 'An overdue task was escalated to farm management.',
        ],
        NotificationType::TASK_COMPLETED => [
            'label' => 'Task completed',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.task_completed',
            'description' => 'A worker completed a task you supervise.',
        ],
        NotificationType::TASK_AWAITING_APPROVAL => [
            'label' => 'Task awaiting approval',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::HIGH,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.task_completed',
            'description' => 'A completed task needs your sign-off.',
        ],
        NotificationType::TASK_APPROVED => [
            'label' => 'Task completion approved',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.task_completed',
            'description' => 'Your completed task was approved.',
        ],
        NotificationType::TASK_REJECTED => [
            'label' => 'Task completion rejected',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::HIGH,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.task_rejected',
            'description' => 'A supervisor rejected your task completion.',
        ],
        NotificationType::TASK_CANCELLED => [
            'label' => 'Task cancelled',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.task_cancelled',
            'description' => 'A task assigned to you was cancelled.',
        ],
        NotificationType::TASK_RECURRING_GENERATED => [
            'label' => 'Recurring tasks generated',
            'category' => NotificationCategory::TASKS,
            'priority' => NotificationPriority::LOW,
            'channels' => ['in_app'],
            'template' => 'emails.notifications.generic',
            'description' => 'New occurrences of a recurring task were created.',
        ],

        /*
        |----------------------------------------------------------------------
        | Farm operations
        |----------------------------------------------------------------------
        */
        NotificationType::FEEDING_REMINDER => [
            'label' => 'Feeding reminder',
            'category' => NotificationCategory::FARM_OPERATIONS,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.feeding_reminder',
            'description' => 'Upcoming feeding schedule items.',
        ],
        NotificationType::ANIMAL_HEALTH_ALERT => [
            'label' => 'Animal health alert',
            'category' => NotificationCategory::FARM_OPERATIONS,
            'priority' => NotificationPriority::HIGH,
            'channels' => ['in_app', 'email'],
            'mandatory' => true,
            'locked' => ['in_app'],
            'template' => 'emails.notifications.animal_health_alert',
            'description' => 'Mortality spikes and other flock health warnings.',
        ],
        NotificationType::FARM_ACTIVITY_ALERT => [
            'label' => 'Farm activity alert',
            'category' => NotificationCategory::FARM_OPERATIONS,
            'priority' => NotificationPriority::LOW,
            'channels' => ['in_app'],
            'template' => 'emails.notifications.generic',
            'description' => 'General farm activity updates.',
        ],

        /*
        |----------------------------------------------------------------------
        | Medication
        |----------------------------------------------------------------------
        */
        NotificationType::MEDICATION_REMINDER => [
            'label' => 'Medication reminder',
            'category' => NotificationCategory::MEDICATION,
            'priority' => NotificationPriority::HIGH,
            'channels' => ['in_app', 'email'],
            'mandatory' => true,
            'locked' => ['in_app'],
            'template' => 'emails.notifications.medication_reminder',
            'description' => 'A medication is due for a flock or animal group.',
        ],
        NotificationType::VACCINATION_REMINDER => [
            'label' => 'Vaccination reminder',
            'category' => NotificationCategory::MEDICATION,
            'priority' => NotificationPriority::HIGH,
            'channels' => ['in_app', 'email'],
            'mandatory' => true,
            'locked' => ['in_app'],
            'template' => 'emails.notifications.medication_reminder',
            'description' => 'A vaccination is scheduled.',
        ],
        NotificationType::MEDICATION_COMPLETED => [
            'label' => 'Medication administered',
            'category' => NotificationCategory::MEDICATION,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.medication_completed',
            'description' => 'A medication task was administered and signed off.',
        ],

        /*
        |----------------------------------------------------------------------
        | Inventory
        |----------------------------------------------------------------------
        */
        NotificationType::INVENTORY_ALERT => [
            'label' => 'Inventory alert',
            'category' => NotificationCategory::INVENTORY,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.inventory_alert',
            'description' => 'Expiring or expired stock.',
        ],
        NotificationType::LOW_STOCK_ALERT => [
            'label' => 'Low stock alert',
            'category' => NotificationCategory::INVENTORY,
            'priority' => NotificationPriority::HIGH,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.low_stock_alert',
            'description' => 'Feed, medication or vaccine stock is running low.',
        ],

        /*
        |----------------------------------------------------------------------
        | Equipment
        |----------------------------------------------------------------------
        */
        NotificationType::EQUIPMENT_MAINTENANCE_DUE => [
            'label' => 'Equipment maintenance due',
            'category' => NotificationCategory::FARM_OPERATIONS,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.generic',
            'description' => 'Scheduled maintenance is approaching for farm equipment.',
        ],
        NotificationType::EQUIPMENT_WARRANTY_EXPIRING => [
            'label' => 'Equipment warranty expiring',
            'category' => NotificationCategory::FARM_OPERATIONS,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.generic',
            'description' => 'Equipment warranty is nearing expiry.',
        ],

        /*
        |----------------------------------------------------------------------
        | System & account
        |----------------------------------------------------------------------
        */
        NotificationType::WELCOME => [
            'label' => 'Welcome message',
            'category' => NotificationCategory::SYSTEM,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'template' => 'emails.notifications.welcome',
            'description' => 'Sent once when your account is created.',
        ],
        NotificationType::ACCOUNT_NOTIFICATION => [
            'label' => 'Account notification',
            'category' => NotificationCategory::ACCOUNT,
            'priority' => NotificationPriority::NORMAL,
            'channels' => ['in_app', 'email'],
            'mandatory' => true,
            'locked' => ['in_app', 'email'],
            'template' => 'emails.notifications.account',
            'description' => 'Changes to your account or farm membership.',
        ],
        NotificationType::SECURITY_NOTIFICATION => [
            'label' => 'Security notification',
            'category' => NotificationCategory::ACCOUNT,
            'priority' => NotificationPriority::CRITICAL,
            'channels' => ['in_app', 'email'],
            'mandatory' => true,
            'locked' => ['in_app', 'email'],
            'template' => 'emails.notifications.security',
            'description' => 'Password and sign-in security alerts.',
        ],
        NotificationType::SYSTEM_ANNOUNCEMENT => [
            'label' => 'System announcement',
            'category' => NotificationCategory::SYSTEM,
            'priority' => NotificationPriority::LOW,
            'channels' => ['in_app'],
            'template' => 'emails.notifications.generic',
            'description' => 'Product news and maintenance windows.',
        ],
        NotificationType::SYSTEM_ALERT => [
            'label' => 'Important system alert',
            'category' => NotificationCategory::SYSTEM,
            'priority' => NotificationPriority::CRITICAL,
            'channels' => ['in_app', 'email'],
            'mandatory' => true,
            'locked' => ['in_app', 'email'],
            'template' => 'emails.notifications.generic',
            'description' => 'Critical service alerts you cannot opt out of.',
        ],
    ],
];
