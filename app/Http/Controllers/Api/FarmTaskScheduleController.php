<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\FarmTaskSchedule;
use App\Models\FarmTaskScheduleAssignee;
use App\Services\Notifications\TaskReminderService;
use App\Services\TaskInstanceGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class FarmTaskScheduleController extends ApiController
{
    public function __construct(
        protected TaskInstanceGenerator $generator,
        protected TaskReminderService $reminders,
    ) {
    }

    public function index(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('view farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $query = FarmTaskSchedule::where('farm_id', $farm->id)
            ->with(['assignees.user:id,name,email', 'template:id,title', 'reminders'])
            ->orderByDesc('created_at');

        if ($request->boolean('recurring_only')) {
            $query->where('recurrence', '!=', 'none');
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('section')) {
            $query->where('section', $request->section);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        return $this->sendResponse($query->get(), 'Task schedules retrieved');
    }

    public function store(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $assigneeIds = array_values($data['assignee_ids'] ?? []);
        $reminderOffsets = array_key_exists('reminders', $data) ? $data['reminders'] : null;
        unset($data['assignee_ids'], $data['reminders']);

        if (!$this->assigneesBelongToFarm($farm, $assigneeIds)) {
            return $this->sendValidationError('Validation failed', [
                'assignee_ids' => ['All assignees must be members of this farm'],
            ]);
        }

        if (!empty($data['flock_id']) && !$this->flockBelongsToFarm($farm, (int) $data['flock_id'])) {
            return $this->sendValidationError('Validation failed', [
                'flock_id' => ['The selected flock does not belong to this farm'],
            ]);
        }

        $schedule = DB::transaction(function () use ($farm, $data, $assigneeIds, $request) {
            $schedule = FarmTaskSchedule::create(array_merge($data, [
                'farm_id' => $farm->id,
                'created_by' => $request->user()->id,
                'priority' => $data['priority'] ?? 'medium',
                'repeat_interval' => $data['repeat_interval'] ?? 1,
                'assignment_mode' => $data['assignment_mode'] ?? 'single',
                'recurrence' => $data['recurrence'] ?? 'none',
                'indefinite' => $data['indefinite'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ]));

            foreach ($assigneeIds as $i => $userId) {
                FarmTaskScheduleAssignee::create([
                    'schedule_id' => $schedule->id,
                    'user_id' => $userId,
                    'sort_order' => $i,
                ]);
            }

            return $schedule;
        });

        // Reminders must exist before instances so occurrences are materialised.
        if ($reminderOffsets !== null) {
            $this->reminders->syncScheduleReminders($schedule, $reminderOffsets);
        } else {
            $this->reminders->applyFarmDefaults($schedule);
        }

        $this->generator->generateForSchedule(
            $schedule->fresh(['assignees']),
            Carbon::today(),
            Carbon::today()->addDays(30)
        );

        $this->generator->audit(
            $farm->id,
            null,
            $schedule->id,
            $request->user()->id,
            'schedule_created',
            ['title' => $schedule->title]
        );

        return $this->sendResponse(
            $schedule->fresh(['assignees.user:id,name,email', 'template:id,title', 'reminders']),
            'Task schedule created',
            201
        );
    }

    public function show(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('view farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $schedule = FarmTaskSchedule::where('farm_id', $farm->id)
            ->with(['assignees.user:id,name,email', 'template', 'reminders'])
            ->findOrFail($id);

        return $this->sendResponse($schedule, 'Task schedule retrieved');
    }

    public function update(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $schedule = FarmTaskSchedule::where('farm_id', $farm->id)->findOrFail($id);
        $validator = Validator::make($request->all(), $this->rules(false));
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $assigneeIds = array_key_exists('assignee_ids', $data)
            ? array_values($data['assignee_ids'] ?? [])
            : null;
        $reminderOffsets = array_key_exists('reminders', $data) ? $data['reminders'] : null;
        unset($data['assignee_ids'], $data['reminders']);

        if ($assigneeIds !== null && !$this->assigneesBelongToFarm($farm, $assigneeIds)) {
            return $this->sendValidationError('Validation failed', [
                'assignee_ids' => ['All assignees must be members of this farm'],
            ]);
        }

        if (array_key_exists('flock_id', $data) && !empty($data['flock_id']) && !$this->flockBelongsToFarm($farm, (int) $data['flock_id'])) {
            return $this->sendValidationError('Validation failed', [
                'flock_id' => ['The selected flock does not belong to this farm'],
            ]);
        }

        DB::transaction(function () use ($schedule, $data, $assigneeIds) {
            $schedule->update($data);
            if ($assigneeIds !== null) {
                $schedule->assignees()->delete();
                foreach ($assigneeIds as $i => $userId) {
                    FarmTaskScheduleAssignee::create([
                        'schedule_id' => $schedule->id,
                        'user_id' => $userId,
                        'sort_order' => $i,
                    ]);
                }
            }
        });

        $this->generator->generateForSchedule(
            $schedule->fresh(['assignees']),
            Carbon::today(),
            Carbon::today()->addDays(30)
        );

        if ($reminderOffsets !== null) {
            $this->reminders->syncScheduleReminders($schedule->fresh() ?? $schedule, $reminderOffsets);
        } else {
            // Times or assignees may have moved, so refresh existing occurrences.
            $this->reminders->materializeForSchedule($schedule->fresh() ?? $schedule);
        }

        $this->generator->audit(
            $farm->id,
            null,
            $schedule->id,
            $request->user()->id,
            'schedule_updated',
            []
        );

        return $this->sendResponse(
            $schedule->fresh(['assignees.user:id,name,email', 'template:id,title', 'reminders']),
            'Task schedule updated'
        );
    }

    public function destroy(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $schedule = FarmTaskSchedule::where('farm_id', $farm->id)->findOrFail($id);
        $schedule->update(['is_active' => false]);
        // Cancel future pending instances
        $schedule->instances()
            ->whereIn('status', ['pending', 'in_progress', 'overdue'])
            ->whereDate('scheduled_date', '>=', Carbon::today())
            ->update(['status' => 'cancelled']);

        $this->generator->audit(
            $farm->id,
            null,
            $schedule->id,
            $request->user()->id,
            'schedule_cancelled',
            []
        );

        return $this->sendResponse($schedule->fresh(), 'Task schedule deactivated');
    }

    public function seedRosterExample(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError(
                'You do not have permission to manage farm tasks. Ask an owner/manager to grant "manage farm tasks".'
            );
        }

        $users = $farm->users()->orderBy('name')->get();
        if ($users->isEmpty()) {
            return $this->sendValidationError('No farm users', [
                'users' => ['This farm has no users. Invite workers before seeding the roster.'],
            ]);
        }

        // Prefer named workers from the example roster; otherwise use first available members.
        $workerA = $users->first(fn ($u) => stripos($u->name, 'john') !== false)
            ?? $users->first();
        $workerB = $users->first(fn ($u) => stripos($u->name, 'rasheed') !== false || stripos($u->name, 'rashid') !== false)
            ?? $users->first(fn ($u) => (int) $u->id !== (int) $workerA->id)
            ?? $workerA;

        $warnings = [];
        if ((int) $workerA->id === (int) $workerB->id) {
            $warnings[] = 'Only one farm user is available, so both roster roles were assigned to '
                . $workerA->name
                . '. Invite a second worker and re-seed (or edit schedules) for true alternating coverage.';
        }

        $startDate = $request->input('start_date') ?: Carbon::today()->toDateString();
        $created = [];

        $defs = [
            [
                'title' => 'Feed Layers',
                'section' => 'layers',
                'start_time' => '06:30:00',
                'due_time' => '07:00:00',
                'recurrence' => 'weekly',
                'days_of_week' => [1, 3, 4, 6], // Mon Wed Thu Sat
                'assignment_mode' => 'alternating',
                'assignees' => [$workerA->id, $workerB->id],
                'priority' => 'high',
            ],
            [
                'title' => 'Feed Broilers / Turkeys / Goats',
                'section' => 'mixed',
                'start_time' => '07:00:00',
                'due_time' => '07:30:00',
                'recurrence' => 'weekly',
                'days_of_week' => [1, 3, 4, 6],
                'assignment_mode' => 'alternating',
                'assignees' => [$workerB->id, $workerA->id],
                'priority' => 'high',
            ],
            [
                'title' => 'Wash Pig Pen',
                'section' => 'pigs',
                'start_time' => '08:00:00',
                'due_time' => '08:30:00',
                'recurrence' => 'daily',
                'days_of_week' => null,
                'assignment_mode' => 'alternating',
                'assignees' => [$workerA->id, $workerB->id],
                'priority' => 'medium',
            ],
            [
                'title' => 'Feed Pigs',
                'section' => 'pigs',
                'start_time' => '08:00:00',
                'due_time' => '08:30:00',
                'recurrence' => 'daily',
                'assignment_mode' => 'alternating',
                'assignees' => [$workerB->id, $workerA->id],
                'priority' => 'high',
            ],
            [
                'title' => 'Layer Medication',
                'section' => 'medication',
                'start_time' => null,
                'due_time' => null,
                'recurrence' => 'daily',
                'assignment_mode' => 'alternating',
                'assignees' => [$workerA->id, $workerB->id],
                'priority' => 'critical',
                'animal_group' => 'Layers',
                'medication_name' => 'Daily flock medication',
                'require_completion_confirmation' => true,
                'require_supervisor_approval' => true,
                'require_signature' => true,
            ],
            [
                'title' => 'Broiler Medication',
                'section' => 'medication',
                'start_time' => null,
                'due_time' => null,
                'recurrence' => 'daily',
                'assignment_mode' => 'alternating',
                'assignees' => [$workerB->id, $workerA->id],
                'priority' => 'critical',
                'animal_group' => 'Broilers',
                'medication_name' => 'Daily flock medication',
                'require_completion_confirmation' => true,
                'require_supervisor_approval' => true,
                'require_signature' => true,
            ],
            [
                'title' => 'Wash Pig Pen (Evening)',
                'section' => 'pigs',
                'start_time' => '16:30:00',
                'due_time' => '17:00:00',
                'recurrence' => 'daily',
                'assignment_mode' => 'alternating',
                'assignees' => [$workerB->id, $workerA->id],
                'priority' => 'medium',
            ],
            [
                'title' => 'Feed Pigs / Broilers / Turkey / Goats',
                'section' => 'mixed',
                'start_time' => '17:00:00',
                'due_time' => '17:30:00',
                'recurrence' => 'daily',
                'assignment_mode' => 'alternating',
                'assignees' => [$workerA->id, $workerB->id],
                'priority' => 'high',
            ],
            [
                'title' => 'Feed Layers (Evening)',
                'section' => 'layers',
                'start_time' => '17:00:00',
                'due_time' => '17:30:00',
                'recurrence' => 'daily',
                'assignment_mode' => 'alternating',
                'assignees' => [$workerB->id, $workerA->id],
                'priority' => 'high',
            ],
            [
                'title' => 'Raise Layer Feeders & Turn Off Lights',
                'section' => 'layers',
                'start_time' => '23:00:00',
                'due_time' => '23:30:00',
                'recurrence' => 'daily',
                'assignment_mode' => 'alternating',
                'assignees' => [$workerA->id, $workerB->id],
                'priority' => 'high',
            ],
        ];

        try {
            DB::transaction(function () use ($defs, $farm, $request, $startDate, &$created) {
                foreach ($defs as $def) {
                    $assigneeIds = array_values(array_unique(array_map('intval', $def['assignees'])));
                    unset($def['assignees']);

                    // One worker only → single assignment (avoids unique assignee constraint)
                    $assignmentMode = count($assigneeIds) < 2 ? 'single' : ($def['assignment_mode'] ?? 'alternating');

                    $schedule = FarmTaskSchedule::create(array_merge($def, [
                        'farm_id' => $farm->id,
                        'description' => 'Seeded from dual-alternating farm roster example',
                        'start_date' => $startDate,
                        'indefinite' => true,
                        'repeat_interval' => 1,
                        'is_active' => true,
                        'created_by' => $request->user()->id,
                        'assignment_mode' => $assignmentMode,
                        'require_completion_confirmation' => (bool) ($def['require_completion_confirmation'] ?? false),
                        'require_supervisor_approval' => (bool) ($def['require_supervisor_approval'] ?? false),
                        'require_signature' => (bool) ($def['require_signature'] ?? false),
                    ]));

                    foreach ($assigneeIds as $i => $uid) {
                        FarmTaskScheduleAssignee::create([
                            'schedule_id' => $schedule->id,
                            'user_id' => $uid,
                            'sort_order' => $i,
                        ]);
                    }

                    // Timed roster tasks get a 30 minute heads-up by default.
                    if ($schedule->start_time || $schedule->due_time) {
                        $this->reminders->syncScheduleReminders($schedule, [30]);
                    }

                    $this->generator->generateForSchedule(
                        $schedule->fresh(['assignees']),
                        Carbon::parse($startDate)->startOfDay(),
                        Carbon::parse($startDate)->startOfDay()->addDays(30)
                    );

                    $created[] = $schedule->fresh(['assignees.user:id,name', 'reminders']);
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return $this->sendError('Failed to seed roster example', [$e->getMessage()], 500);
        }

        return $this->sendResponse([
            'schedules' => $created,
            'warnings' => $warnings,
            'workers' => [
                'a' => ['id' => $workerA->id, 'name' => $workerA->name],
                'b' => ['id' => $workerB->id, 'name' => $workerB->name],
            ],
        ], 'Roster example schedules seeded', 201);
    }

    protected function assigneesBelongToFarm(Farm $farm, array $ids): bool
    {
        if (empty($ids)) {
            return true;
        }
        $count = $farm->users()->whereIn('users.id', $ids)->count();

        return $count === count(array_unique($ids));
    }

    protected function flockBelongsToFarm(Farm $farm, int $flockId): bool
    {
        return $farm->flocks()->whereKey($flockId)->exists();
    }

    protected function rules(bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'template_id' => 'nullable|integer|exists:farm_task_templates,id',
            'title' => "{$req}|string|max:255",
            'description' => 'nullable|string',
            'section' => "{$req}|string|in:layers,broilers,turkeys,goats,pigs,medication,feeding,cleaning,general,mixed",
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'instructions' => 'nullable|string',
            'notes' => 'nullable|string',
            'start_date' => "{$req}|date",
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'indefinite' => 'nullable|boolean',
            'start_time' => 'nullable|date_format:H:i,H:i:s',
            'due_time' => 'nullable|date_format:H:i,H:i:s',
            'recurrence' => 'nullable|string|in:none,daily,weekly,monthly,custom',
            'repeat_interval' => 'nullable|integer|min:1|max:365',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:1|max:7',
            'month_day' => 'nullable|integer|min:1|max:31',
            'assignment_mode' => 'nullable|string|in:single,alternating,all',
            'assignee_ids' => 'nullable|array',
            'assignee_ids.*' => 'integer|exists:users,id',
            'reminders_enabled' => 'nullable|boolean',
            'reminders' => 'nullable|array|max:5',
            'reminders.*' => 'integer|min:0|max:10080',
            'animal_group' => 'nullable|string|max:255',
            'flock_id' => 'nullable|integer|exists:flocks,id',
            'medication_name' => 'nullable|string|max:255',
            'dosage_instructions' => 'nullable|string',
            'require_completion_confirmation' => 'nullable|boolean',
            'require_supervisor_approval' => 'nullable|boolean',
            'require_signature' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ];
    }
}
