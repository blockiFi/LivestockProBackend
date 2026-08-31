<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Models\FarmTaskSchedule;
use App\Models\ScheduledNotification;
use App\Services\Notifications\TaskReminderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reminder configuration for a recurring schedule or a single task instance.
 */
class FarmTaskReminderController extends ApiController
{
    public function __construct(protected TaskReminderService $reminders)
    {
    }

    public function presets()
    {
        $presets = collect(config('notifications.reminders.presets', []))
            ->map(fn (int $minutes) => [
                'offset_minutes' => $minutes,
                'label' => \App\Models\FarmTaskReminder::describeOffset($minutes),
            ])
            ->values();

        return $this->sendResponse([
            'presets' => $presets,
            'max_per_task' => (int) config('notifications.reminders.max_per_task', 5),
        ], 'Reminder presets retrieved');
    }

    public function scheduleReminders(Request $request, $farm, $scheduleId)
    {
        [$farm, $error] = $this->authorizeFarm($request, $farm, 'view farm tasks');
        if ($error) {
            return $error;
        }

        $schedule = FarmTaskSchedule::where('farm_id', $farm->id)->findOrFail($scheduleId);

        return $this->sendResponse([
            'reminders_enabled' => (bool) $schedule->reminders_enabled,
            'reminders' => $schedule->reminders()->get(),
        ], 'Schedule reminders retrieved');
    }

    public function updateScheduleReminders(Request $request, $farm, $scheduleId)
    {
        [$farm, $error] = $this->authorizeFarm($request, $farm, 'manage farm tasks');
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'reminders_enabled' => 'nullable|boolean',
            'reminders' => 'present|array|max:5',
            'reminders.*' => 'integer|min:0|max:10080',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $schedule = FarmTaskSchedule::where('farm_id', $farm->id)->findOrFail($scheduleId);

        if ($request->has('reminders_enabled')) {
            $schedule->update(['reminders_enabled' => $request->boolean('reminders_enabled')]);
        }

        $this->reminders->syncScheduleReminders($schedule, $request->input('reminders', []));

        return $this->sendResponse([
            'reminders_enabled' => (bool) $schedule->fresh()->reminders_enabled,
            'reminders' => $schedule->reminders()->get(),
        ], 'Schedule reminders updated');
    }

    public function instanceReminders(Request $request, $farm, $instanceId)
    {
        [$farm, $error] = $this->authorizeFarm($request, $farm, 'view farm tasks');
        if ($error) {
            return $error;
        }

        $instance = FarmTaskInstance::where('farm_id', $farm->id)->findOrFail($instanceId);

        return $this->sendResponse([
            'reminders' => $this->reminders->remindersFor($instance)->values(),
            'has_instance_override' => $instance->reminders()->exists(),
            'upcoming' => ScheduledNotification::query()
                ->where('instance_id', $instance->id)
                ->where('status', ScheduledNotification::STATUS_PENDING)
                ->orderBy('scheduled_for')
                ->get(['id', 'type', 'offset_minutes', 'scheduled_for', 'user_id']),
        ], 'Task reminders retrieved');
    }

    public function updateInstanceReminders(Request $request, $farm, $instanceId)
    {
        [$farm, $error] = $this->authorizeFarm($request, $farm, 'manage farm tasks');
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'reminders' => 'present|array|max:5',
            'reminders.*' => 'integer|min:0|max:10080',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $instance = FarmTaskInstance::where('farm_id', $farm->id)->findOrFail($instanceId);
        $this->reminders->syncInstanceReminders($instance, $request->input('reminders', []));

        return $this->sendResponse([
            'reminders' => $this->reminders->remindersFor($instance->fresh() ?? $instance)->values(),
            'upcoming' => ScheduledNotification::query()
                ->where('instance_id', $instance->id)
                ->where('status', ScheduledNotification::STATUS_PENDING)
                ->orderBy('scheduled_for')
                ->get(['id', 'type', 'offset_minutes', 'scheduled_for', 'user_id']),
        ], 'Task reminders updated');
    }

    /**
     * @return array{0: Farm|null, 1: \Illuminate\Http\JsonResponse|null}
     */
    protected function authorizeFarm(Request $request, $farm, string $permission): array
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can($permission)) {
            return [null, $this->sendUnauthorizedError()];
        }

        return [$farm, null];
    }
}
