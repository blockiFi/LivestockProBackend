<?php

namespace App\Services\Notifications;

use App\Models\Farm;
use App\Models\FarmTaskCompletion;
use App\Models\FarmTaskInstance;
use App\Models\User;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPriority;
use App\Notifications\NotificationType;
use Carbon\CarbonImmutable;

/**
 * Task module adapter over the central notification service.
 *
 * Everything task related that is worth telling someone about is expressed
 * here, so controllers and the instance generator never build notification
 * payloads themselves.
 */
class FarmTaskNotifier
{
    public const MANAGER_PERMISSIONS = ['approve farm tasks', 'manage farm tasks'];

    public function __construct(
        protected NotificationService $notifications,
        protected TaskReminderService $reminders,
    ) {
    }

    /**
     * @param  list<int>  $userIds
     */
    public function taskAssigned(FarmTaskInstance $instance, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $this->notifications->send(
            $this->base($instance, NotificationType::TASK_ASSIGNED)
                ->to(...$userIds)
                ->title('New task assigned: ' . $instance->title)
                ->body($this->whenLine($instance))
                ->dedupe('task_assigned:i' . $instance->id)
        );
    }

    public function taskReassigned(
        FarmTaskInstance $instance,
        ?int $newAssigneeId,
        ?int $previousAssigneeId,
        ?User $actor = null,
    ): void {
        $previousName = $previousAssigneeId ? User::find($previousAssigneeId)?->name : null;

        if ($newAssigneeId) {
            $this->notifications->send(
                $this->base($instance, NotificationType::TASK_REASSIGNED)
                    ->to($newAssigneeId)
                    ->title('Task reassigned to you: ' . $instance->title)
                    ->body($this->whenLine($instance))
                    ->with(['previous_assignee' => $previousName])
                    ->dedupe('task_reassigned:i' . $instance->id . ':to' . $newAssigneeId)
            );
        }

        if ($previousAssigneeId && $previousAssigneeId !== $newAssigneeId) {
            $newName = $newAssigneeId ? User::find($newAssigneeId)?->name : 'someone else';

            $this->notifications->send(
                $this->base($instance, NotificationType::TASK_REASSIGNED)
                    ->to($previousAssigneeId)
                    ->title('Task moved off your list: ' . $instance->title)
                    ->body($instance->title . ' is now assigned to ' . $newName . '.')
                    ->with(['assigned_to' => $newName])
                    ->dedupe('task_unassigned:i' . $instance->id . ':from' . $previousAssigneeId)
            );
        }

        // The reminder set follows the new assignee.
        $this->reminders->cancelPendingForInstance($instance);
        $this->reminders->materializeForInstance($instance->fresh() ?? $instance);
    }

    /**
     * @param  list<string>  $changes
     */
    public function taskUpdated(FarmTaskInstance $instance, array $changes = []): void
    {
        if (!$instance->assigned_to_user_id) {
            return;
        }

        $this->notifications->send(
            $this->base($instance, NotificationType::TASK_UPDATED)
                ->to($instance->assigned_to_user_id)
                ->title('Task updated: ' . $instance->title)
                ->body($changes === [] ? $this->whenLine($instance) : implode(', ', $changes))
                ->with(['changes' => $changes === [] ? null : implode(', ', $changes)])
                ->dedupe('task_updated:i' . $instance->id . ':' . md5(implode('|', $changes) . $instance->updated_at))
        );
    }

    public function taskCancelled(FarmTaskInstance $instance, ?User $actor = null, ?string $reason = null): void
    {
        $this->reminders->cancelPendingForInstance($instance);

        if (!$instance->assigned_to_user_id) {
            return;
        }

        $this->notifications->send(
            $this->base($instance, NotificationType::TASK_CANCELLED)
                ->to($instance->assigned_to_user_id)
                ->except($actor)
                ->title('Task cancelled: ' . $instance->title)
                ->body($reason ?: 'This task no longer needs to be done.')
                ->with([
                    'cancelled_by' => $actor?->name,
                    'cancellation_reason' => $reason,
                ])
                ->dedupe('task_cancelled:i' . $instance->id)
        );
    }

    /**
     * Completion sign-off: tells managers, and uses the medication template when
     * the task is a medication task.
     */
    public function taskCompleted(FarmTaskInstance $instance, FarmTaskCompletion $completion, User $completedBy): void
    {
        $this->reminders->cancelPendingForInstance($instance);

        $farm = $this->farm($instance);
        $config = $farm?->notificationConfigOrDefault();

        $awaiting = (bool) $instance->awaiting_approval;
        $type = $awaiting
            ? NotificationType::TASK_AWAITING_APPROVAL
            : ($instance->section === 'medication'
                ? NotificationType::MEDICATION_COMPLETED
                : NotificationType::TASK_COMPLETED);

        if ($config && !$config->notify_managers_on_completion && !$awaiting) {
            return;
        }

        $title = $awaiting
            ? 'Sign-off needed: ' . $instance->title
            : $instance->title . ' completed';

        $this->notifications->send(
            $this->base($instance, $type)
                ->toFarmMembersWithPermission(...self::MANAGER_PERMISSIONS)
                ->except($completedBy)
                ->title($title)
                ->body($completedBy->name . ' completed this task on ' . $this->dateLine($instance) . '.')
                ->with($this->completionVariables($instance, $completion, $completedBy))
                ->dedupe(($awaiting ? 'task_awaiting_approval:' : 'task_completed:') . 'i' . $instance->id)
        );
    }

    public function completionApproved(FarmTaskInstance $instance, FarmTaskCompletion $completion, User $approver): void
    {
        $recipient = $completion->completed_by ?: $instance->assigned_to_user_id;

        if (!$recipient) {
            return;
        }

        $this->notifications->send(
            $this->base($instance, NotificationType::TASK_APPROVED)
                ->to($recipient)
                ->except($approver)
                ->title('Approved: ' . $instance->title)
                ->body($approver->name . ' approved your completion of this task.')
                ->with(array_merge(
                    $this->completionVariables($instance, $completion, null),
                    ['approved_by' => $approver->name]
                ))
                ->dedupe('task_approved:i' . $instance->id)
        );
    }

    public function completionRejected(
        FarmTaskInstance $instance,
        FarmTaskCompletion $completion,
        User $approver,
        ?string $reason = null,
    ): void {
        $recipient = $completion->completed_by ?: $instance->assigned_to_user_id;

        if (!$recipient) {
            return;
        }

        $this->notifications->send(
            $this->base($instance, NotificationType::TASK_REJECTED)
                ->to($recipient)
                ->title('Completion rejected: ' . $instance->title)
                ->body($reason ?: $approver->name . ' asked for this task to be done again.')
                ->with([
                    'approved_by' => $approver->name,
                    'rejection_reason' => $reason,
                ])
                ->dedupe('task_rejected:i' . $instance->id . ':' . now()->format('YmdHi'))
        );
    }

    public function taskOverdue(FarmTaskInstance $instance): void
    {
        if ($instance->assigned_to_user_id) {
            $this->notifications->send(
                $this->base($instance, NotificationType::TASK_OVERDUE)
                    ->to($instance->assigned_to_user_id)
                    ->title('Overdue: ' . $instance->title)
                    ->body('This task was due ' . $this->dueLine($instance) . ' and is still open.')
                    ->priority(NotificationPriority::HIGH)
                    ->with(['escalation_stage' => 'Assigned worker notified'])
                    ->dedupe('task_overdue:i' . $instance->id)
            );
        }

        $farm = $this->farm($instance);
        $config = $farm?->notificationConfigOrDefault();

        if ($config?->notify_managers_on_overdue) {
            $this->notifications->send(
                $this->base($instance, NotificationType::TASK_OVERDUE)
                    ->toFarmMembersWithPermission(...self::MANAGER_PERMISSIONS)
                    ->except($instance->assigned_to_user_id)
                    ->title('Overdue: ' . $instance->title)
                    ->body(($instance->assignee?->name ?? 'Unassigned') . ' has not completed this task. Due ' . $this->dueLine($instance) . '.')
                    ->priority(NotificationPriority::HIGH)
                    ->with([
                        'escalation_stage' => 'Managers notified',
                        'assigned_to' => $instance->assignee?->name,
                    ])
                    ->dedupe('task_overdue_managers:i' . $instance->id)
            );
        }

        $this->reminders->cancelPendingForInstance($instance);
    }

    /**
     * Escalation stage for an overdue task. `stage` is part of the dedupe key so
     * each stage fires exactly once per instance.
     */
    public function escalateOverdue(FarmTaskInstance $instance, string $stage, string $priority, ?int $overdueMinutes = null): void
    {
        $assigneeName = $instance->assignee?->name ?? 'Unassigned';

        $this->notifications->send(
            $this->base($instance, NotificationType::TASK_ESCALATED)
                ->toFarmMembersWithPermission(...self::MANAGER_PERMISSIONS)
                ->title('Escalated overdue task: ' . $instance->title)
                ->body(sprintf(
                    '%s has not completed this task. Due %s.',
                    $assigneeName,
                    $this->dueLine($instance)
                ))
                ->priority($priority)
                ->with([
                    'escalation_stage' => $stage,
                    'assigned_to' => $assigneeName,
                    'overdue_for' => $overdueMinutes !== null ? $this->humanizeMinutes($overdueMinutes) : null,
                ])
                ->dedupe('task_escalated:i' . $instance->id . ':' . $stage)
        );
    }

    protected function base(FarmTaskInstance $instance, string $type): NotificationMessage
    {
        return NotificationMessage::make($type)
            ->farm($instance->farm_id)
            ->taskInstance($instance)
            ->section($instance->section)
            ->priority($this->priorityFor($instance, $type))
            ->action('/dashboard/poultry/tasks?instance=' . $instance->id, 'View task')
            ->payload(['instance_id' => $instance->id, 'schedule_id' => $instance->schedule_id]);
    }

    protected function priorityFor(FarmTaskInstance $instance, string $type): ?string
    {
        // Critical tasks lift the notification priority with them.
        if ($instance->priority === 'critical') {
            return NotificationPriority::CRITICAL;
        }

        if ($instance->priority === 'high' && $type !== NotificationType::TASK_UPDATED) {
            return NotificationPriority::HIGH;
        }

        return null;
    }

    protected function completionVariables(
        FarmTaskInstance $instance,
        FarmTaskCompletion $completion,
        ?User $completedBy,
    ): array {
        $timezone = $this->farm($instance)?->resolveTimezone() ?? config('app.timezone', 'UTC');

        return array_filter([
            'completed_by' => $completedBy?->name ?? User::find($completion->completed_by)?->name,
            'completed_at' => $completion->completed_at
                ? CarbonImmutable::parse($completion->completed_at)->setTimezone($timezone)->format('D, d M Y g:i A')
                : null,
            'completion_notes' => $completion->notes,
            'signature_text' => $completion->signature_text ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function whenLine(FarmTaskInstance $instance): string
    {
        $parts = [ucfirst(str_replace('_', ' ', (string) $instance->section)), $this->dateLine($instance)];

        if ($instance->start_time) {
            $parts[] = 'at ' . CarbonImmutable::parse($instance->start_time)->format('g:i A');
        }

        return implode(' · ', array_filter($parts));
    }

    protected function dateLine(FarmTaskInstance $instance): string
    {
        return CarbonImmutable::parse($instance->scheduled_date)->format('D, d M Y');
    }

    protected function dueLine(FarmTaskInstance $instance): string
    {
        $date = $this->dateLine($instance);

        if ($instance->due_time) {
            return $date . ' at ' . CarbonImmutable::parse($instance->due_time)->format('g:i A');
        }

        return $date;
    }

    protected function humanizeMinutes(int $minutes): string
    {
        if ($minutes >= 1440) {
            $days = intdiv($minutes, 1440);

            return $days === 1 ? '1 day' : $days . ' days';
        }

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 hour' : $hours . ' hours';
        }

        return max(1, $minutes) . ' minutes';
    }

    protected function farm(FarmTaskInstance $instance): ?Farm
    {
        return $instance->farm ?: Farm::with('settings')->find($instance->farm_id);
    }
}
