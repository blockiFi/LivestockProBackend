<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Models\FarmTaskSchedule;
use App\Models\FarmTaskScheduleAssignee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\TaskInstanceGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarmTaskManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $john;
    private User $rasheed;
    private Farm $farm;
    private string $token;
    private TaskInstanceGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = app(TaskInstanceGenerator::class);

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->john = User::factory()->create(['name' => 'John']);
        $this->rasheed = User::factory()->create(['name' => 'Rasheed']);
        $this->token = $this->owner->createToken('test-token')->plainTextToken;

        $country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $this->owner->id,
            'country_id' => $country->id,
        ]);

        $permissions = collect([
            'view farm tasks',
            'manage farm tasks',
            'complete farm tasks',
            'approve farm tasks',
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $role = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $role->givePermissionTo($permissions);

        foreach ([$this->owner, $this->john, $this->rasheed] as $user) {
            $this->farm->users()->attach($user->id);
            $user->assignRole($role);
        }
    }

    public function test_alternating_assignees_rotate_by_occurrence(): void
    {
        $schedule = FarmTaskSchedule::create([
            'farm_id' => $this->farm->id,
            'title' => 'Feed Layers',
            'section' => 'layers',
            'priority' => 'high',
            'start_date' => '2026-08-24', // Monday
            'indefinite' => true,
            'start_time' => '06:30:00',
            'due_time' => '07:00:00',
            'recurrence' => 'weekly',
            'repeat_interval' => 1,
            'days_of_week' => [1, 3, 4, 6], // Mon Wed Thu Sat
            'assignment_mode' => 'alternating',
            'is_active' => true,
            'created_by' => $this->owner->id,
        ]);

        FarmTaskScheduleAssignee::create([
            'schedule_id' => $schedule->id,
            'user_id' => $this->john->id,
            'sort_order' => 0,
        ]);
        FarmTaskScheduleAssignee::create([
            'schedule_id' => $schedule->id,
            'user_id' => $this->rasheed->id,
            'sort_order' => 1,
        ]);

        $this->generator->generateForSchedule(
            $schedule->fresh(['assignees']),
            Carbon::parse('2026-08-24'),
            Carbon::parse('2026-08-30')
        );

        $instances = FarmTaskInstance::where('schedule_id', $schedule->id)
            ->orderBy('scheduled_date')
            ->get();

        $this->assertCount(4, $instances);
        $this->assertEquals('2026-08-24', $instances[0]->scheduled_date->toDateString());
        $this->assertEquals($this->john->id, $instances[0]->assigned_to_user_id);
        $this->assertEquals($this->rasheed->id, $instances[1]->assigned_to_user_id);
        $this->assertEquals($this->john->id, $instances[2]->assigned_to_user_id);
        $this->assertEquals($this->rasheed->id, $instances[3]->assigned_to_user_id);
    }

    public function test_create_schedule_via_api_generates_instances(): void
    {
        $response = $this->withToken($this->token)->postJson(
            "/api/farms/{$this->farm->id}/task-schedules",
            [
                'title' => 'Feed Pigs',
                'section' => 'pigs',
                'priority' => 'medium',
                'start_date' => Carbon::today()->toDateString(),
                'indefinite' => true,
                'start_time' => '08:00',
                'recurrence' => 'daily',
                'repeat_interval' => 1,
                'assignment_mode' => 'alternating',
                'assignee_ids' => [$this->john->id, $this->rasheed->id],
            ]
        );

        $response->assertStatus(201)->assertJsonPath('success', true);

        $this->assertGreaterThan(0, FarmTaskInstance::where('farm_id', $this->farm->id)->count());
    }

    public function test_complete_and_approve_medication_task(): void
    {
        $schedule = FarmTaskSchedule::create([
            'farm_id' => $this->farm->id,
            'title' => 'Layer Medication',
            'section' => 'medication',
            'priority' => 'critical',
            'start_date' => Carbon::today()->toDateString(),
            'indefinite' => true,
            'recurrence' => 'none',
            'assignment_mode' => 'single',
            'require_completion_confirmation' => true,
            'require_supervisor_approval' => true,
            'require_signature' => true,
            'is_active' => true,
            'created_by' => $this->owner->id,
        ]);
        FarmTaskScheduleAssignee::create([
            'schedule_id' => $schedule->id,
            'user_id' => $this->john->id,
            'sort_order' => 0,
        ]);

        $this->generator->generateForSchedule(
            $schedule->fresh(['assignees']),
            Carbon::today(),
            Carbon::today()
        );

        $instance = FarmTaskInstance::where('schedule_id', $schedule->id)->firstOrFail();

        $johnToken = $this->john->createToken('john')->plainTextToken;
        $complete = $this->withToken($johnToken)->postJson(
            "/api/farms/{$this->farm->id}/task-instances/{$instance->id}/complete",
            [
                'worker_confirmed' => true,
                'signature_text' => 'John',
                'notes' => 'Done',
            ]
        );
        $complete->assertOk()->assertJsonPath('data.awaiting_approval', true);

        $approve = $this->withToken($this->token)->postJson(
            "/api/farms/{$this->farm->id}/task-instances/{$instance->id}/approve",
            ['approval_notes' => 'Looks good']
        );
        $approve->assertOk()->assertJsonPath('data.awaiting_approval', false);
    }

    public function test_seed_roster_example_with_two_workers(): void
    {
        $response = $this->withToken($this->token)->postJson(
            "/api/farms/{$this->farm->id}/task-schedules/seed-roster-example",
            ['start_date' => '2026-08-26']
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workers.a.name', 'John')
            ->assertJsonPath('data.workers.b.name', 'Rasheed');

        $this->assertCount(10, $response->json('data.schedules'));
        $this->assertEmpty($response->json('data.warnings'));
        $this->assertGreaterThan(0, FarmTaskInstance::where('farm_id', $this->farm->id)->count());
    }

    public function test_seed_roster_example_works_with_single_farm_user(): void
    {
        $this->farm->users()->detach([$this->john->id, $this->rasheed->id]);

        $response = $this->withToken($this->token)->postJson(
            "/api/farms/{$this->farm->id}/task-schedules/seed-roster-example",
            ['start_date' => '2026-08-26']
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workers.a.name', 'Owner')
            ->assertJsonPath('data.workers.b.name', 'Owner');

        $this->assertCount(10, $response->json('data.schedules'));
        $this->assertNotEmpty($response->json('data.warnings'));
        $this->assertSame('single', FarmTaskSchedule::where('farm_id', $this->farm->id)->first()->assignment_mode);
    }
}
