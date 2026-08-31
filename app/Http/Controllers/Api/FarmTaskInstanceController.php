<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\FarmTaskCompletion;
use App\Models\FarmTaskInstance;
use App\Services\Notifications\FarmTaskNotifier;
use App\Services\Notifications\TaskReminderService;
use App\Services\TaskInstanceGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class FarmTaskInstanceController extends ApiController
{
    public function __construct(
        protected TaskInstanceGenerator $generator,
        protected FarmTaskNotifier $notifier,
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

        $query = FarmTaskInstance::where('farm_id', $farm->id)
            ->with([
                'assignee:id,name,email',
                'completion.completedByUser:id,name',
                'completion.approvedByUser:id,name',
                'schedule:id,title,recurrence,assignment_mode',
            ])
            ->orderBy('scheduled_date')
            ->orderByRaw('CASE WHEN start_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('start_time');

        if ($request->filled('date')) {
            $query->whereDate('scheduled_date', $request->date);
        }
        if ($request->filled('from')) {
            $query->whereDate('scheduled_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('scheduled_date', '<=', $request->to);
        }
        if ($request->filled('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }
        if ($request->filled('section')) {
            $query->where('section', $request->section);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('assigned_to')) {
            if ($request->assigned_to === 'me') {
                $query->where('assigned_to_user_id', $request->user()->id);
            } else {
                $query->where('assigned_to_user_id', (int) $request->assigned_to);
            }
        }
        if ($request->boolean('awaiting_approval')) {
            $query->where('awaiting_approval', true);
        }
        if ($request->boolean('medication_only')) {
            $query->where('section', 'medication');
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        if ($request->boolean('paginate')) {
            $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
            $page = $query->paginate($perPage);

            return $this->sendResponse([
                'data' => $page->items(),
                'current_page' => $page->currentPage(),
                'total_pages' => $page->lastPage(),
                'total_records' => $page->total(),
            ], 'Task instances retrieved');
        }

        return $this->sendResponse($query->get(), 'Task instances retrieved');
    }

    public function stats(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('view farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $today = Carbon::today()->toDateString();
        $base = FarmTaskInstance::where('farm_id', $farm->id);

        $stats = [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', 'pending')->count(),
            'in_progress' => (clone $base)->where('status', 'in_progress')->count(),
            'completed_today' => (clone $base)->where('status', 'completed')
                ->whereHas('completion', fn ($q) => $q->whereDate('completed_at', $today))
                ->count(),
            'overdue' => (clone $base)->where('status', 'overdue')->count(),
            'due_today' => (clone $base)->whereDate('scheduled_date', $today)
                ->whereIn('status', ['pending', 'in_progress', 'overdue'])
                ->count(),
            'medication' => (clone $base)->where('section', 'medication')
                ->whereIn('status', ['pending', 'in_progress', 'overdue', 'completed'])
                ->count(),
            'awaiting_approval' => (clone $base)->where('awaiting_approval', true)->count(),
        ];

        return $this->sendResponse($stats, 'Task stats retrieved');
    }

    public function show(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('view farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $instance = FarmTaskInstance::where('farm_id', $farm->id)
            ->with([
                'assignee:id,name,email',
                'assignees:id,name,email',
                'completion.completedByUser:id,name',
                'completion.approvedByUser:id,name',
                'schedule.assignees.user:id,name',
            ])
            ->findOrFail($id);

        return $this->sendResponse($instance, 'Task instance retrieved');
    }

    public function start(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        $instance = FarmTaskInstance::where('farm_id', $farm->id)->findOrFail($id);
        if ($err = $this->ensureCanAct($request, $instance)) {
            return $err;
        }
        if (!in_array($instance->status, ['pending', 'overdue'], true)) {
            return $this->sendValidationError('Invalid status', ['status' => ['Only pending/overdue tasks can be started']]);
        }

        $instance->update([
            'status' => 'in_progress',
            'started_at' => now(),
            'started_by' => $request->user()->id,
        ]);

        $this->generator->audit($farm->id, $instance->id, $instance->schedule_id, $request->user()->id, 'task_started', []);

        return $this->sendResponse($instance->fresh(['assignee:id,name']), 'Task started');
    }

    public function complete(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        $instance = FarmTaskInstance::where('farm_id', $farm->id)->findOrFail($id);
        if ($err = $this->ensureCanAct($request, $instance)) {
            return $err;
        }
        if (in_array($instance->status, ['completed', 'cancelled', 'skipped'], true)) {
            return $this->sendValidationError('Invalid status', ['status' => ['Task already closed']]);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
            'worker_confirmed' => 'nullable|boolean',
            'signature_text' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        if ($instance->require_completion_confirmation && !$request->boolean('worker_confirmed')) {
            return $this->sendValidationError('Confirmation required', [
                'worker_confirmed' => ['Please confirm task completion'],
            ]);
        }
        if ($instance->require_signature && !trim((string) $request->input('signature_text'))) {
            return $this->sendValidationError('Signature required', [
                'signature_text' => ['Please provide a sign-off name/signature'],
            ]);
        }

        $awaiting = (bool) $instance->require_supervisor_approval;
        $completion = null;

        DB::transaction(function () use ($instance, $request, $awaiting, $farm, &$completion) {
            $completion = FarmTaskCompletion::updateOrCreate(
                ['instance_id' => $instance->id],
                [
                    'completed_by' => $request->user()->id,
                    'completed_at' => now(),
                    'notes' => $request->input('notes'),
                    'worker_confirmed' => $request->boolean('worker_confirmed'),
                    'signature_text' => $request->input('signature_text'),
                    'supervisor_approved' => !$awaiting,
                    'approved_by' => $awaiting ? null : $request->user()->id,
                    'approved_at' => $awaiting ? null : now(),
                ]
            );

            $instance->update([
                'status' => 'completed',
                'awaiting_approval' => $awaiting,
            ]);

            $this->generator->audit(
                $farm->id,
                $instance->id,
                $instance->schedule_id,
                $request->user()->id,
                'task_completed',
                ['awaiting_approval' => $awaiting]
            );
        });

        if ($completion) {
            $this->notifier->taskCompleted($instance->fresh() ?? $instance, $completion, $request->user());
        }

        return $this->sendResponse(
            $instance->fresh(['assignee:id,name', 'completion.completedByUser:id,name']),
            'Task completed'
        );
    }

    public function approve(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('approve farm tasks') && !$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $instance = FarmTaskInstance::where('farm_id', $farm->id)->with('completion')->findOrFail($id);
        if (!$instance->awaiting_approval || !$instance->completion) {
            return $this->sendValidationError('Nothing to approve', ['approval' => ['Task is not awaiting approval']]);
        }

        $instance->completion->update([
            'supervisor_approved' => true,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'approval_notes' => $request->input('approval_notes'),
        ]);
        $instance->update(['awaiting_approval' => false]);

        $this->generator->audit(
            $farm->id,
            $instance->id,
            $instance->schedule_id,
            $request->user()->id,
            'task_approved',
            []
        );

        $this->notifier->completionApproved($instance, $instance->completion, $request->user());

        return $this->sendResponse($instance->fresh(['completion.approvedByUser:id,name']), 'Task approved');
    }

    public function reject(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('approve farm tasks') && !$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $instance = FarmTaskInstance::where('farm_id', $farm->id)->with('completion')->findOrFail($id);
        if (!$instance->completion) {
            return $this->sendValidationError('Nothing to reject', ['approval' => ['Task has no completion record']]);
        }

        $reason = $request->input('reason');

        $instance->completion->update([
            'supervisor_approved' => false,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'approval_notes' => $reason,
        ]);

        // Send the task back to the worker so it shows up as outstanding again.
        $instance->update(['status' => 'pending', 'awaiting_approval' => false]);

        $this->generator->audit(
            $farm->id,
            $instance->id,
            $instance->schedule_id,
            $request->user()->id,
            'task_rejected',
            ['reason' => $reason]
        );

        $this->notifier->completionRejected($instance, $instance->completion, $request->user(), $reason);
        $this->reminders->materializeForInstance($instance->fresh() ?? $instance);

        return $this->sendResponse($instance->fresh(['completion.approvedByUser:id,name']), 'Task completion rejected');
    }

    public function skip(Request $request, $farm, $id)
    {
        return $this->transition($request, $farm, $id, 'skipped', ['pending', 'overdue', 'in_progress']);
    }

    public function cancel(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        return $this->transition($request, $farm, $id, 'cancelled', ['pending', 'overdue', 'in_progress'], false);
    }

    public function reassign(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!$request->user()->can('manage farm tasks')) {
            return $this->sendUnauthorizedError();
        }

        $validator = Validator::make($request->all(), [
            'assigned_to_user_id' => 'required|integer|exists:users,id',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $userId = (int) $request->assigned_to_user_id;
        if (!$farm->users()->where('users.id', $userId)->exists()) {
            return $this->sendValidationError('Invalid assignee', [
                'assigned_to_user_id' => ['User must be a farm member'],
            ]);
        }

        $instance = FarmTaskInstance::where('farm_id', $farm->id)->findOrFail($id);
        if (in_array($instance->status, ['completed', 'cancelled', 'skipped'], true)) {
            return $this->sendValidationError('Invalid status', ['status' => ['Cannot reassign a closed task']]);
        }

        $old = $instance->assigned_to_user_id;
        $instance->update(['assigned_to_user_id' => $userId]);

        $this->generator->audit($farm->id, $instance->id, $instance->schedule_id, $request->user()->id, 'task_reassigned', [
            'from' => $old,
            'to' => $userId,
        ]);

        $this->notifier->taskReassigned($instance, $userId, $old ? (int) $old : null, $request->user());

        return $this->sendResponse($instance->fresh(['assignee:id,name']), 'Task reassigned');
    }

    protected function transition(
        Request $request,
        $farm,
        $id,
        string $status,
        array $allowedFrom,
        bool $checkAct = true
    ) {
        $farm = $farm instanceof Farm ? $farm : Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        $instance = FarmTaskInstance::where('farm_id', $farm->id)->findOrFail($id);
        if ($checkAct) {
            if ($err = $this->ensureCanAct($request, $instance)) {
                return $err;
            }
        }
        if (!in_array($instance->status, $allowedFrom, true)) {
            return $this->sendValidationError('Invalid status', ['status' => ["Cannot mark as {$status} from {$instance->status}"]]);
        }

        $instance->update(['status' => $status, 'awaiting_approval' => false]);
        $this->generator->audit($farm->id, $instance->id, $instance->schedule_id, $request->user()->id, "task_{$status}", []);

        if ($status === 'cancelled') {
            $this->notifier->taskCancelled($instance, $request->user(), $request->input('reason'));
        } else {
            $this->reminders->cancelPendingForInstance($instance);
        }

        return $this->sendResponse($instance->fresh(['assignee:id,name']), "Task {$status}");
    }

    protected function ensureCanAct(Request $request, FarmTaskInstance $instance)
    {
        $user = $request->user();
        if ($user->can('manage farm tasks') || $user->can('complete farm tasks')) {
            if (
                !$user->can('manage farm tasks')
                && (int) $instance->assigned_to_user_id !== (int) $user->id
                && !$instance->assignees()->where('users.id', $user->id)->exists()
            ) {
                return $this->sendUnauthorizedError('You can only act on your assigned tasks');
            }

            return null;
        }

        return $this->sendUnauthorizedError();
    }
}
