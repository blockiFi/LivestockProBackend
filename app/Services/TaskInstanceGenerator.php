<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\FarmTaskAuditLog;
use App\Models\FarmTaskInstance;
use App\Models\FarmTaskSchedule;
use App\Models\FarmTaskScheduleAssignee;
use App\Services\Notifications\FarmTaskNotifier;
use App\Services\Notifications\TaskReminderService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TaskInstanceGenerator
{
    public function __construct(
        protected FarmTaskNotifier $notifier,
        protected TaskReminderService $reminders,
    ) {
    }

    public function generateForSchedule(FarmTaskSchedule $schedule, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $schedule->loadMissing('assignees');

        $from = ($from ?? Carbon::today())->copy()->startOfDay();
        $to = ($to ?? Carbon::today()->addDays(30))->copy()->startOfDay();

        $scheduleStart = Carbon::parse($schedule->start_date)->startOfDay();
        if ($from->lt($scheduleStart)) {
            $from = $scheduleStart->copy();
        }

        if (!$schedule->indefinite && $schedule->end_date) {
            $scheduleEnd = Carbon::parse($schedule->end_date)->startOfDay();
            if ($to->gt($scheduleEnd)) {
                $to = $scheduleEnd->copy();
            }
        }

        if ($to->lt($from)) {
            return 0;
        }

        $dates = $this->expandDates($schedule, $from, $to);
        $created = 0;

        foreach ($dates as $occurrenceIndex => $date) {
            $assigneeIds = $this->resolveAssignees($schedule, $occurrenceIndex);
            $primaryAssignee = $assigneeIds[0] ?? null;

            $instance = $this->upsertInstance($schedule, $date, $occurrenceIndex, $primaryAssignee);
            if ($instance->wasRecentlyCreated) {
                $created++;
                $this->notifyAssignment($instance, $assigneeIds);
                $this->audit($schedule->farm_id, $instance->id, $schedule->id, null, 'instance_generated', [
                    'scheduled_date' => $date->toDateString(),
                    'assigned_to' => $primaryAssignee,
                ]);
            } elseif (
                $instance->status === 'pending'
                && (int) $instance->assigned_to_user_id !== (int) ($primaryAssignee ?? 0)
            ) {
                $instance->update(['assigned_to_user_id' => $primaryAssignee]);
            }

            if ($schedule->assignment_mode === 'all' && !empty($assigneeIds)) {
                $instance->assignees()->sync($assigneeIds);
            }

            // Reminder occurrences are materialised per instance and are
            // idempotent, so regenerating a recurring schedule is safe.
            $this->reminders->materializeForInstance($instance->fresh() ?? $instance);
        }

        return $created;
    }

    public function generateForFarm(int $farmId, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $total = 0;
        FarmTaskSchedule::query()
            ->where('farm_id', $farmId)
            ->where('is_active', true)
            ->with('assignees')
            ->chunkById(50, function ($schedules) use (&$total, $from, $to) {
                foreach ($schedules as $schedule) {
                    $total += $this->generateForSchedule($schedule, $from, $to);
                }
            });

        return $total;
    }

    public function markOverdueForFarm(int $farmId, ?Carbon $now = null): int
    {
        $farm = Farm::find($farmId);
        $timezone = $farm?->resolveTimezone() ?? config('app.timezone', 'UTC');
        $now = $now ? $now->copy()->setTimezone($timezone) : Carbon::now($timezone);
        $today = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        $query = FarmTaskInstance::query()
            ->where('farm_id', $farmId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('scheduled_date', '<', $today)
                    ->orWhere(function ($q2) use ($today, $currentTime) {
                        $q2->where('scheduled_date', $today)
                            ->whereNotNull('due_time')
                            ->where('due_time', '<', $currentTime);
                    });
            });

        $count = 0;
        $query->chunkById(100, function ($instances) use (&$count) {
            foreach ($instances as $instance) {
                $instance->update(['status' => 'overdue']);
                $count++;
                $this->audit($instance->farm_id, $instance->id, $instance->schedule_id, null, 'status_overdue', []);
                $this->notifier->taskOverdue($instance);
            }
        });

        return $count;
    }

    /**
     * @return array<int, Carbon> keyed by occurrence index
     */
    public function expandDates(FarmTaskSchedule $schedule, Carbon $from, Carbon $to): array
    {
        return $this->expandDatesStable($schedule, $from, $to);
    }

    /**
     * Stable expansion: count matching days from start_date for occurrence_index.
     *
     * @return array<int, Carbon>
     */
    protected function expandDatesStable(FarmTaskSchedule $schedule, Carbon $from, Carbon $to): array
    {
        $result = [];
        $interval = max(1, (int) $schedule->repeat_interval);
        $cursor = Carbon::parse($schedule->start_date)->startOfDay();
        $matchCount = 0;
        $guard = 0;

        while ($cursor->lte($to) && $guard < 5000) {
            $guard++;

            if ($schedule->recurrence === 'none') {
                if ($cursor->betweenIncluded($from, $to)) {
                    $result[0] = $cursor->copy();
                }
                break;
            }

            if ($this->isMatchingDay($schedule, $cursor, $interval)) {
                if ($cursor->gte($from)) {
                    $result[$matchCount] = $cursor->copy();
                }
                $matchCount++;
            }

            $cursor->addDay();
        }

        return $result;
    }

    protected function isMatchingDay(FarmTaskSchedule $schedule, Carbon $date, int $interval): bool
    {
        return match ($schedule->recurrence) {
            'daily' => $this->dailyMatches($schedule, $date, $interval),
            'weekly', 'custom' => $this->weeklyMatches($schedule, $date, $interval),
            'monthly' => $this->monthlyMatches($schedule, $date, $interval),
            default => false,
        };
    }

    protected function dailyMatches(FarmTaskSchedule $schedule, Carbon $date, int $interval): bool
    {
        $start = Carbon::parse($schedule->start_date)->startOfDay();
        if ($date->lt($start)) {
            return false;
        }
        $daysSince = $start->diffInDays($date);

        return $daysSince % $interval === 0;
    }

    protected function weeklyMatches(FarmTaskSchedule $schedule, Carbon $date, int $interval): bool
    {
        $days = $schedule->days_of_week;
        if (!is_array($days) || empty($days)) {
            $days = [(int) Carbon::parse($schedule->start_date)->dayOfWeekIso];
        }
        $days = array_map('intval', $days);
        if (!in_array((int) $date->dayOfWeekIso, $days, true)) {
            return false;
        }

        $start = Carbon::parse($schedule->start_date)->startOfWeek();
        $weeksSince = (int) floor($start->diffInDays($date->copy()->startOfWeek()) / 7);

        return $weeksSince % $interval === 0 && $date->gte(Carbon::parse($schedule->start_date)->startOfDay());
    }

    protected function monthlyMatches(FarmTaskSchedule $schedule, Carbon $date, int $interval): bool
    {
        $day = (int) ($schedule->month_day ?: Carbon::parse($schedule->start_date)->day);
        if ((int) $date->day !== $day) {
            return false;
        }
        $start = Carbon::parse($schedule->start_date)->startOfMonth();
        $monthsSince = ($date->year - $start->year) * 12 + ($date->month - $start->month);

        return $monthsSince % $interval === 0 && $date->gte(Carbon::parse($schedule->start_date)->startOfDay());
    }

    /**
     * @return list<int>
     */
    public function resolveAssignees(FarmTaskSchedule $schedule, int $occurrenceIndex): array
    {
        /** @var Collection<int, FarmTaskScheduleAssignee> $assignees */
        $assignees = $schedule->assignees->sortBy('sort_order')->values();
        if ($assignees->isEmpty()) {
            return [];
        }

        $ids = $assignees->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return match ($schedule->assignment_mode) {
            'all' => $ids,
            'alternating' => [$ids[$occurrenceIndex % count($ids)]],
            default => [$ids[0]],
        };
    }

    protected function upsertInstance(
        FarmTaskSchedule $schedule,
        Carbon $date,
        int $occurrenceIndex,
        ?int $primaryAssignee
    ): FarmTaskInstance {
        $startTime = $schedule->start_time
            ? Carbon::parse($schedule->start_time)->format('H:i:s')
            : null;

        $existing = FarmTaskInstance::query()
            ->where('schedule_id', $schedule->id)
            ->whereDate('scheduled_date', $date->toDateString())
            ->when(
                $startTime === null,
                fn ($q) => $q->whereNull('start_time'),
                fn ($q) => $q->where('start_time', $startTime)
            )
            ->first();

        $payload = [
            'farm_id' => $schedule->farm_id,
            'flock_id' => $schedule->flock_id,
            'schedule_id' => $schedule->id,
            'title' => $schedule->title,
            'description' => $schedule->description,
            'section' => $schedule->section,
            'priority' => $schedule->priority,
            'instructions' => $schedule->instructions,
            'notes' => $schedule->notes,
            'scheduled_date' => $date->toDateString(),
            'start_time' => $startTime,
            'due_time' => $schedule->due_time
                ? Carbon::parse($schedule->due_time)->format('H:i:s')
                : null,
            'assigned_to_user_id' => $primaryAssignee,
            'animal_group' => $schedule->animal_group,
            'medication_name' => $schedule->medication_name,
            'dosage_instructions' => $schedule->dosage_instructions,
            'require_completion_confirmation' => $schedule->require_completion_confirmation,
            'require_supervisor_approval' => $schedule->require_supervisor_approval,
            'require_signature' => $schedule->require_signature,
            'occurrence_index' => $occurrenceIndex,
        ];

        if ($existing) {
            // Do not overwrite terminal statuses
            if (!in_array($existing->status, ['completed', 'cancelled', 'skipped'], true)) {
                $existing->fill(collect($payload)->except(['scheduled_date', 'start_time'])->all());
                $existing->save();
            }

            return $existing;
        }

        return FarmTaskInstance::create(array_merge($payload, [
            'status' => 'pending',
            'awaiting_approval' => false,
        ]));
    }

    /**
     * @param  list<int>  $assigneeIds
     */
    protected function notifyAssignment(FarmTaskInstance $instance, array $assigneeIds): void
    {
        $this->notifier->taskAssigned($instance, array_values(array_map('intval', $assigneeIds)));
    }

    public function audit(
        int $farmId,
        ?int $instanceId,
        ?int $scheduleId,
        ?int $actorId,
        string $action,
        array $meta = []
    ): void {
        FarmTaskAuditLog::create([
            'farm_id' => $farmId,
            'instance_id' => $instanceId,
            'schedule_id' => $scheduleId,
            'actor_id' => $actorId,
            'action' => $action,
            'meta' => $meta,
        ]);
    }
}
