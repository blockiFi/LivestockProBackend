<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPriority;
use App\Notifications\NotificationType;

/**
 * Account and security events that are not farm-scoped.
 */
class AccountNotifier
{
    public function __construct(protected NotificationService $notifications)
    {
    }

    public function welcome(User $user): void
    {
        $this->notifications->send(
            NotificationMessage::make(NotificationType::WELCOME)
                ->to($user)
                ->title('Welcome to LivestockPro')
                ->body('Your account is ready. Select a farm to start managing tasks, flocks, and inventory.')
                ->action('/farm-selection', 'Choose a farm')
                ->dedupe('welcome:u' . $user->id)
                ->with(['user_name' => $user->name])
        );
    }

    public function passwordChanged(User $user): void
    {
        $this->notifications->send(
            NotificationMessage::make(NotificationType::SECURITY_NOTIFICATION)
                ->to($user)
                ->priority(NotificationPriority::CRITICAL)
                ->title('Your password was changed')
                ->body('If you did not make this change, reset your password immediately and contact your farm administrator.')
                ->action('/dashboard/settings/security', 'Review security')
                ->dedupe('password_changed:u' . $user->id . ':' . now()->format('YmdHi'))
                ->with(['user_name' => $user->name])
        );
    }

    public function accountUpdated(User $user, string $title, string $body, ?string $actionUrl = null): void
    {
        $this->notifications->send(
            NotificationMessage::make(NotificationType::ACCOUNT_NOTIFICATION)
                ->to($user)
                ->title($title)
                ->body($body)
                ->action($actionUrl, $actionUrl ? 'Open' : null)
                ->dedupe('account:u' . $user->id . ':' . md5($title . $body))
                ->with(['user_name' => $user->name])
        );
    }
}
