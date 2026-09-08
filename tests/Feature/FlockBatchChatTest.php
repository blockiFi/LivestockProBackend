<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockChatMemory;
use App\Models\FlockChatMessage;
use App\Models\FlockChatSession;
use App\Models\FlockDailyRecord;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithSubscriptions;
use Tests\TestCase;

class FlockBatchChatTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithSubscriptions;

    private User $user;

    private Farm $farm;

    private Flock $flock;

    private string $token;

    private string $base;

    /** @var list<array{content:?string,tool_calls:list}> */
    private array $llmQueue = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $this->user->id,
            'country_id' => $country->id,
        ]);

        $this->putFarmOnPlan($this->farm, SubscriptionPlan::PREMIUM);

        $permissions = [
            'view flocks',
            'update flocks',
            'manage flocks',
            'create records',
            'manage records',
            'create flock egg reports',
            'create flock mortality reports',
            'create sales',
            'manage sales',
        ];

        $role = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        foreach ($permissions as $name) {
            $permission = Permission::findOrCreate($name, 'api');
            $role->givePermissionTo($permission);
        }

        $this->farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
        $this->user->assignRole($role);

        $poultryType = PoultryType::factory()->create();
        $flockStage = FlockStage::factory()->create(['poultry_type_id' => $poultryType->id]);
        $house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $poultryType->id,
        ]);

        $this->flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $poultryType->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 100,
            'status' => 'active',
        ]);

        $this->base = "/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/batch-chat";

        $this->mockLlm();
    }

    private function mockLlm(): void
    {
        $mock = $this->createMock(LlmService::class);
        $mock->method('getLastError')->willReturn(null);
        $mock->method('chat')->willReturn('Compressed memory summary for testing.');
        $mock->method('chatWithTools')->willReturnCallback(function () {
            if ($this->llmQueue === []) {
                return [
                    'content' => 'Default assistant reply.',
                    'tool_calls' => [],
                    'raw' => null,
                ];
            }

            return array_shift($this->llmQueue);
        });

        $this->app->instance(LlmService::class, $mock);
    }

    private function queueLlm(array ...$responses): void
    {
        foreach ($responses as $response) {
            $this->llmQueue[] = [
                'content' => $response['content'] ?? null,
                'tool_calls' => $response['tool_calls'] ?? [],
                'raw' => null,
            ];
        }
    }

    public function test_can_create_and_list_sessions(): void
    {
        $create = $this->withToken($this->token)->postJson("{$this->base}/sessions");
        $create->assertCreated()->assertJsonPath('success', true);

        $id = $create->json('data.id');
        $this->assertNotNull($id);

        $second = $this->withToken($this->token)->postJson("{$this->base}/sessions");
        $second->assertCreated();

        $list = $this->withToken($this->token)->getJson("{$this->base}/sessions");
        $list->assertOk();
        $this->assertCount(2, $list->json('data.sessions'));
    }

    public function test_message_persists_history_and_auto_titles(): void
    {
        $this->queueLlm(['content' => 'You have 100 birds remaining.']);

        $sessionId = $this->withToken($this->token)->postJson("{$this->base}/sessions")->json('data.id');

        $res = $this->withToken($this->token)->postJson("{$this->base}/sessions/{$sessionId}/messages", [
            'content' => 'How many birds are left?',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.assistant_message.content', 'You have 100 birds remaining.');

        $this->assertDatabaseHas('flock_chat_sessions', [
            'id' => $sessionId,
            'title' => 'How many birds are left?',
        ]);

        $messages = $this->withToken($this->token)->getJson("{$this->base}/sessions/{$sessionId}/messages");
        $messages->assertOk();
        $this->assertCount(2, $messages->json('data.messages'));
    }

    public function test_write_tool_stays_pending_until_confirm(): void
    {
        $this->queueLlm([
            'content' => 'I can log that egg collection.',
            'tool_calls' => [[
                'id' => 'call_eggs_1',
                'name' => 'create_egg_report',
                'arguments' => [
                    'date' => now()->toDateString(),
                    'eggs_collected' => 90,
                    'eggs_broken' => 2,
                ],
            ]],
        ]);

        // Follow-up after confirm
        $this->queueLlm(['content' => 'Egg report saved successfully.']);

        $sessionId = $this->withToken($this->token)->postJson("{$this->base}/sessions")->json('data.id');

        $res = $this->withToken($this->token)->postJson("{$this->base}/sessions/{$sessionId}/messages", [
            'content' => 'Record 90 eggs collected today with 2 broken',
        ]);

        $res->assertOk();
        $pending = $res->json('data.pending_actions');
        $this->assertIsArray($pending);
        $this->assertCount(1, $pending);
        $this->assertSame('create_egg_report', $pending[0]['tool']);
        $this->assertDatabaseCount('poultry_flock_egg_reports', 0);

        $actionId = $pending[0]['id'];
        $confirm = $this->withToken($this->token)->postJson(
            "{$this->base}/sessions/{$sessionId}/actions/{$actionId}/confirm"
        );

        $confirm->assertOk()
            ->assertJsonPath('data.tool_result.ok', true);

        $this->assertDatabaseCount('poultry_flock_egg_reports', 1);
        $this->assertDatabaseHas('flock_chat_memories', [
            'flock_id' => $this->flock->id,
            'user_id' => $this->user->id,
            'kind' => 'action_outcome',
        ]);
    }

    public function test_cancel_pending_action_does_not_write(): void
    {
        $this->queueLlm([
            'content' => 'Confirm mortality?',
            'tool_calls' => [[
                'id' => 'call_mort_1',
                'name' => 'create_mortality_report',
                'arguments' => [
                    'date' => now()->toDateString(),
                    'mortality_count' => 3,
                ],
            ]],
        ]);

        $sessionId = $this->withToken($this->token)->postJson("{$this->base}/sessions")->json('data.id');
        $res = $this->withToken($this->token)->postJson("{$this->base}/sessions/{$sessionId}/messages", [
            'content' => 'Log 3 dead birds',
        ]);
        $actionId = $res->json('data.pending_actions.0.id');

        $cancel = $this->withToken($this->token)->postJson(
            "{$this->base}/sessions/{$sessionId}/actions/{$actionId}/cancel"
        );
        $cancel->assertOk();
        $this->assertDatabaseCount('poultry_mortality_reports', 0);
    }

    public function test_confirm_fails_without_permission(): void
    {
        // Strip write perms — keep view flocks only
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
        $this->user->syncPermissions([Permission::findOrCreate('view flocks', 'api')]);

        $this->queueLlm([
            'content' => 'Ready to create daily record',
            'tool_calls' => [[
                'id' => 'call_daily_1',
                'name' => 'create_daily_record',
                'arguments' => [
                    'date' => now()->toDateString(),
                    'mortality_count' => 0,
                ],
            ]],
        ]);

        // Re-assign view flocks via role for message endpoint
        $viewRole = Role::create([
            'name' => 'viewer',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $viewRole->givePermissionTo(Permission::findOrCreate('view flocks', 'api'));
        $this->user->syncRoles([$viewRole]);

        $sessionId = $this->withToken($this->token)->postJson("{$this->base}/sessions")->json('data.id');
        $res = $this->withToken($this->token)->postJson("{$this->base}/sessions/{$sessionId}/messages", [
            'content' => 'Create today daily record',
        ]);
        $actionId = $res->json('data.pending_actions.0.id');
        $this->assertNotNull($actionId);

        $confirm = $this->withToken($this->token)->postJson(
            "{$this->base}/sessions/{$sessionId}/actions/{$actionId}/confirm"
        );
        $confirm->assertOk();
        $this->assertFalse($confirm->json('data.tool_result.ok'));
        $this->assertDatabaseCount('flock_daily_records', 0);
    }

    public function test_read_tool_executes_immediately(): void
    {
        $this->queueLlm(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_stock',
                    'name' => 'get_egg_stock',
                    'arguments' => ['date' => now()->toDateString()],
                ]],
            ],
            ['content' => 'Available egg stock is 0 today.']
        );

        $sessionId = $this->withToken($this->token)->postJson("{$this->base}/sessions")->json('data.id');
        $res = $this->withToken($this->token)->postJson("{$this->base}/sessions/{$sessionId}/messages", [
            'content' => 'What is my egg stock?',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.assistant_message.content', 'Available egg stock is 0 today.');
        $this->assertSame([], $res->json('data.pending_actions'));
    }

    public function test_memory_summary_compresses_when_window_overflows(): void
    {
        $session = FlockChatSession::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'user_id' => $this->user->id,
            'status' => 'active',
            'last_message_at' => now(),
        ]);

        for ($i = 0; $i < 30; $i++) {
            FlockChatMessage::create([
                'session_id' => $session->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message number {$i}",
            ]);
        }

        $this->queueLlm(['content' => 'Got it, continuing from memory.']);

        $res = $this->withToken($this->token)->postJson("{$this->base}/sessions/{$session->id}/messages", [
            'content' => 'Continue please',
        ]);
        $res->assertOk();

        $session->refresh();
        $this->assertNotEmpty($session->memory_summary);
    }

    public function test_delete_session_and_clear_memories(): void
    {
        $sessionId = $this->withToken($this->token)->postJson("{$this->base}/sessions")->json('data.id');

        FlockChatMemory::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'user_id' => $this->user->id,
            'kind' => 'fact',
            'content' => 'Prefers crates',
            'source_session_id' => $sessionId,
        ]);

        $this->withToken($this->token)->deleteJson("{$this->base}/sessions/{$sessionId}")->assertOk();
        $this->assertDatabaseMissing('flock_chat_sessions', ['id' => $sessionId]);

        $memories = $this->withToken($this->token)->getJson("{$this->base}/memories");
        $memories->assertOk();
        $this->assertCount(1, $memories->json('data.memories'));

        $this->withToken($this->token)->deleteJson("{$this->base}/memories")->assertOk();
        $this->assertDatabaseCount('flock_chat_memories', 0);
    }

    public function test_ai_entitlement_required(): void
    {
        $this->putFarmOnPlan($this->farm, SubscriptionPlan::BASIC);

        $res = $this->withToken($this->token)->postJson("{$this->base}/sessions");
        $res->assertStatus(403);
    }

    public function test_confirmed_daily_record_creates_row(): void
    {
        $date = now()->toDateString();
        $this->queueLlm(
            [
                'content' => 'Confirm daily record?',
                'tool_calls' => [[
                    'id' => 'call_d1',
                    'name' => 'create_daily_record',
                    'arguments' => [
                        'date' => $date,
                        'mortality_count' => 1,
                        'eggs_collected' => 40,
                    ],
                ]],
            ],
            ['content' => 'Daily record saved.']
        );

        $sessionId = $this->withToken($this->token)->postJson("{$this->base}/sessions")->json('data.id');
        $msgRes = $this->withToken($this->token)->postJson("{$this->base}/sessions/{$sessionId}/messages", [
            'content' => 'Create daily record',
        ]);
        $msgRes->assertOk();
        $pending = $msgRes->json('data.pending_actions.0.id');
        $this->assertNotEmpty($pending);

        $confirm = $this->withToken($this->token)->postJson(
            "{$this->base}/sessions/{$sessionId}/actions/{$pending}/confirm"
        );
        $confirm->assertOk();

        $this->assertTrue(
            FlockDailyRecord::where('flock_id', $this->flock->id)->whereDate('date', $date)->exists()
        );
    }
}
