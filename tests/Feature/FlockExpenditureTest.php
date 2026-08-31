<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockExpenditure;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlockExpenditureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Flock $flock;
    private string $token;

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

        $permissions = collect([
            'update flocks',
            'view feed usages',
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($permissions);

        $this->farm->users()->attach($this->user->id);
        $this->user->assignRole($ownerRole);

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
    }

    public function test_manual_expenditure_can_be_updated(): void
    {
        $expenditure = FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'other',
            'amount' => 1500,
            'currency' => 'NGN',
            'description' => 'Transport',
            'date' => now()->toDateString(),
            'source_type' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/expenditures/{$expenditure->id}", [
                'category' => 'other',
                'amount' => 2200,
                'description' => 'Transport and labour',
                'date' => now()->subDay()->toDateString(),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.amount', '2200.00')
            ->assertJsonPath('data.description', 'Transport and labour');

        $this->assertEquals(2200.0, (float) $expenditure->fresh()->amount);
        $this->assertEquals($this->user->id, $expenditure->fresh()->updated_by);
    }

    public function test_auto_generated_expenditure_date_can_be_updated(): void
    {
        $poultryType = PoultryType::factory()->create();
        $feedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $poultryType->id,
            'name' => 'Starter',
            'description' => 'Test',
        ]);
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'FEED-DATE',
            'created_by' => $this->user->id,
        ]);

        $usage = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'poultry_feed_inventory_id' => $inventory->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 10,
            'unit_cost' => 2.5,
            'usage_date' => now()->subDays(3)->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $expenditure = FlockExpenditure::recordFromFeedUsage($usage);
        $this->assertNotNull($expenditure);

        $newDate = now()->subDay()->toDateString();

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/expenditures/{$expenditure->id}", [
                'date' => $newDate,
            ])
            ->assertStatus(200);

        $this->assertEquals($newDate, $expenditure->fresh()->date->toDateString());
        $this->assertEquals($newDate, $usage->fresh()->usage_date->toDateString());
        $this->assertEquals(25.0, (float) $expenditure->fresh()->amount);
    }

    public function test_auto_generated_expenditure_amount_cannot_be_updated(): void
    {
        $expenditure = FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'feed',
            'amount' => 500,
            'description' => 'Feed usage',
            'date' => now()->toDateString(),
            'source_type' => 'feed_usage',
            'source_id' => 1,
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/expenditures/{$expenditure->id}", [
                'amount' => 999,
            ])
            ->assertStatus(422);

        $this->assertEquals(500.0, (float) $expenditure->fresh()->amount);
    }

    public function test_can_create_labour_expenditure_with_payment_metadata(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/expenditures", [
                'category' => 'labour',
                'amount' => 3500,
                'currency' => 'NGN',
                'description' => 'Farmhand weekly wages',
                'payment_method' => 'cash',
                'reference_no' => 'WAGE-001',
                'date' => now()->toDateString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.category', 'labour')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.reference_no', 'WAGE-001');
    }

    public function test_expenditure_summary_returns_category_breakdown(): void
    {
        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'feed',
            'amount' => 1000,
            'date' => now()->toDateString(),
            'source_type' => 'feed_usage',
            'source_id' => 10,
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'labour',
            'amount' => 500,
            'date' => now()->toDateString(),
            'source_type' => 'manual',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/expenditures/summary");

        $response->assertStatus(200)
            ->assertJsonPath('data.total_cost', 1500)
            ->assertJsonPath('data.auto_total', 1000)
            ->assertJsonPath('data.manual_total', 500)
            ->assertJsonPath('data.entry_count', 2);

        $categories = collect($response->json('data.by_category'))->pluck('category')->all();
        $this->assertContains('feed', $categories);
        $this->assertContains('labour', $categories);
    }
}
