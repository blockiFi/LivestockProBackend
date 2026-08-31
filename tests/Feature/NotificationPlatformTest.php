<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Models\FarmTaskSchedule;
use App\Models\FarmTaskScheduleAssignee;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Services\Notifications\TaskReminderService;
use App\Services\TaskInstanceGenerator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationPlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $john;
    private Farm $farm;
    private string $ownerToken;
    private string $johnToken;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = User::factory()->create(['name' => 'Owner', 'email' => 'owner@example.com']);
        $this->john = User::factory()->create(['name' => 'John', 'email' => 'john@example.com']);
        $this->ownerToken = $this->owner->createToken('test-token')->plainTextToken;
        $this->johnToken = $this->john->createToken('john')->plainTextToken;

        $country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $this->owner->id,
            'country_id' => $country->id,
        ]);

        $permissions = collect([
            'view farm',
            'manage farm settings',
            'view farm tasks',
            'manage farm tasks',
            'complete farm tasks',
            'approve farm tasks',
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);

        $role = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $role->givePermissionTo($permissions);

        foreach ([$this->owner, $this->john] as $user) {
            $this->farm->users()->attach($user->id);
            $user->assignRole($role);
        }
    }

    public function test_registering_sends_a_welcome_notification(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'secret12',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('notifications_center', [
            'type' => NotificationType::WELCOME,
            'title' => 'Welcome to LivestockPro',
        ]);
    }

    public function test_task_assignment_creates_in_app_notification_and_is_deduped(): void
    {
        $response = $this->withToken($this->ownerToken)->postJson(
            "/api/farms/{$this->farm->id}/task-schedules",
            [
                'title' => 'Feed Layers',
                'section' => 'layers',
                'priority' => 'high',
                'start_date' => Carbon::today()->toDateString(),
                'indefinite' => true,
                'start_time' => '06:30',
                'recurrence' => 'none',
                'assignment_mode' => 'single',
                'assignee_ids' => [$this->john->id],
                'reminders' => [30],
            ]
        );

        $response->assertCreated();

        $assigned = Notification::query()
            ->where('user_id', $this->john->id)
            ->where('type', NotificationType::TASK_ASSIGNED)
            ->get();

        $this->assertGreaterThan(0, $assigned->count());

        $count = $assigned->count();
        $schedule = FarmTaskSchedule::where('farm_id', $this->farm->id)->firstOrFail();
        app(TaskInstanceGenerator::class)->generateForSchedule(
            $schedule->fresh(['assignees']),
            Carbon::today(),
            Carbon::today()->addDays(7)
        );

        $this->assertEquals(
            $count,
            Notification::query()
                ->where('user_id', $this->john->id)
                ->where('type', NotificationType::TASK_ASSIGNED)
                ->count()
        );
    }

    public function test_recurring_reminders_materialize_per_instance_without_duplicates(): void
    {
        $schedule = FarmTaskSchedule::create([
            'farm_id' => $this->farm->id,
            'title' => 'Feed Layers',
            'section' => 'layers',
            'priority' => 'high',
            'start_date' => '2026-08-24',
            'indefinite' => true,
            'start_time' => '06:30:00',
            'due_time' => '07:00:00',
            'recurrence' => 'weekly',
            'repeat_interval' => 1,
            'days_of_week' => [1, 3, 4, 6],
            'assignment_mode' => 'single',
            'reminders_enabled' => true,
            'is_active' => true,
            'created_by' => $this->owner->id,
        ]);

        FarmTaskScheduleAssignee::create([
            'schedule_id' => $schedule->id,
            'user_id' => $this->john->id,
            'sort_order' => 0,
        ]);

        $reminders = app(TaskReminderService::class);
        $reminders->syncScheduleReminders($schedule, [30]);

        app(TaskInstanceGenerator::class)->generateForSchedule(
            $schedule->fresh(['assignees']),
            Carbon::parse('2026-08-24'),
            Carbon::parse('2026-08-30')
        );

        $instances = FarmTaskInstance::where('schedule_id', $schedule->id)->count();
        $this->assertEquals(4, $instances);

        $firstPass = ScheduledNotification::where('farm_id', $this->farm->id)->count();
        $this->assertEquals(4, $firstPass);

        $reminders->materializeForSchedule($schedule->fresh());
        $this->assertEquals($firstPass, ScheduledNotification::where('farm_id', $this->farm->id)->count());
    }

    public function test_due_reminders_fire_once_and_appear_in_the_centre(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 05:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 05:00:00', 'UTC'));

        $schedule = FarmTaskSchedule::create([
            'farm_id' => $this->farm->id,
            'title' => 'Feed Layers',
            'section' => 'layers',
            'priority' => 'high',
            'start_date' => '2026-08-26',
            'indefinite' => true,
            'start_time' => '06:30:00',
            'recurrence' => 'none',
            'assignment_mode' => 'single',
            'reminders_enabled' => true,
            'is_active' => true,
            'created_by' => $this->owner->id,
        ]);
        FarmTaskScheduleAssignee::create([
            'schedule_id' => $schedule->id,
            'user_id' => $this->john->id,
            'sort_order' => 0,
        ]);

        $reminders = app(TaskReminderService::class);
        $reminders->syncScheduleReminders($schedule, [30]);
        app(TaskInstanceGenerator::class)->generateForSchedule(
            $schedule->fresh(['assignees']),
            Carbon::parse('2026-08-26'),
            Carbon::parse('2026-08-26')
        );

        Carbon::setTestNow(Carbon::parse('2026-08-26 06:01:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 06:01:00', 'UTC'));

        $first = $reminders->processDue();
        $this->assertGreaterThan(0, $first['sent']);

        $this->assertDatabaseHas('notifications_center', [
            'user_id' => $this->john->id,
            'type' => NotificationType::TASK_DUE_SOON,
        ]);

        $count = Notification::where('user_id', $this->john->id)
            ->where('type', NotificationType::TASK_DUE_SOON)
            ->count();

        $second = $reminders->processDue();
        $this->assertEquals(0, $second['sent']);
        $this->assertEquals(
            $count,
            Notification::where('user_id', $this->john->id)
                ->where('type', NotificationType::TASK_DUE_SOON)
                ->count()
        );

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_notification_centre_lists_and_marks_read(): void
    {
        $this->withToken($this->ownerToken)->postJson(
            "/api/farms/{$this->farm->id}/task-schedules",
            [
                'title' => 'Wash Pig Pen',
                'section' => 'pigs',
                'start_date' => Carbon::today()->toDateString(),
                'indefinite' => true,
                'start_time' => '08:00',
                'recurrence' => 'none',
                'assignment_mode' => 'single',
                'assignee_ids' => [$this->john->id],
            ]
        )->assertCreated();

        $this->assertGreaterThan(
            0,
            Notification::where('user_id', $this->john->id)->count()
        );

        $list = $this->actingAs($this->john, 'sanctum')->getJson('/api/notifications');
        $list->assertOk()->assertJsonPath('success', true);
        $this->assertNotEmpty($list->json('data'));

        $id = $list->json('data.0.id');
        $this->actingAs($this->john, 'sanctum')
            ->postJson("/api/notifications/{$id}/read")
            ->assertOk();

        $summary = $this->actingAs($this->john, 'sanctum')->getJson('/api/notifications/summary');
        $summary->assertOk();
        $this->assertArrayHasKey('unread', $summary->json('data'));
    }

    public function test_unread_only_query_accepts_true_string(): void
    {
        $this->withToken($this->ownerToken)->postJson(
            "/api/farms/{$this->farm->id}/task-schedules",
            [
                'title' => 'Feed Pigs',
                'section' => 'pigs',
                'start_date' => Carbon::today()->toDateString(),
                'indefinite' => true,
                'start_time' => '08:00',
                'recurrence' => 'none',
                'assignment_mode' => 'single',
                'assignee_ids' => [$this->john->id],
            ]
        )->assertCreated();

        $this->actingAs($this->john, 'sanctum')
            ->getJson("/api/notifications?farm_id={$this->farm->id}&limit=80&unread_only=true")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('errors.unread_only');

        $this->actingAs($this->john, 'sanctum')
            ->getJson("/api/notifications?unread_only=1")
            ->assertOk();
    }

    public function test_user_cannot_disable_mandatory_in_app_channel(): void
    {
        $this->actingAs($this->john, 'sanctum')->putJson('/api/notifications/preferences', [
            'farm_id' => $this->farm->id,
            'preferences' => [
                [
                    'type' => NotificationType::TASK_OVERDUE,
                    'in_app' => false,
                    'email' => false,
                ],
            ],
        ])->assertOk();

        $prefs = $this->actingAs($this->john, 'sanctum')
            ->getJson('/api/notifications/preferences?farm_id=' . $this->farm->id)
            ->assertOk()
            ->json('data.preferences');

        $this->assertTrue($prefs[NotificationType::TASK_OVERDUE]['in_app']);
    }

    public function test_platform_broadcast_appears_when_filtering_by_active_farm(): void
    {
        Notification::create([
            'user_id' => $this->owner->id,
            'type' => 'platform_broadcast',
            'title' => 'Scheduled maintenance',
            'body' => 'The platform will be down tonight.',
            'category' => 'system',
            'priority' => 'normal',
            'status' => 'delivered',
        ]);

        Notification::create([
            'user_id' => $this->owner->id,
            'farm_id' => $this->farm->id,
            'type' => NotificationType::TASK_ASSIGNED,
            'title' => 'Farm task assigned',
            'body' => 'Check the feeders.',
            'category' => 'tasks',
            'priority' => 'normal',
            'status' => 'delivered',
        ]);

        $response = $this->withToken($this->ownerToken)
            ->getJson("/api/notifications?farm_id={$this->farm->id}")
            ->assertOk();

        $types = collect($response->json('data'))->pluck('type')->all();

        $this->assertContains('platform_broadcast', $types);
        $this->assertContains(NotificationType::TASK_ASSIGNED, $types);

        $summary = $this->withToken($this->ownerToken)
            ->getJson("/api/notifications/summary?farm_id={$this->farm->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $summary['unread']);
        $this->assertTrue(
            collect($summary['latest'])->contains(fn (array $row) => $row['type'] === 'platform_broadcast')
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
