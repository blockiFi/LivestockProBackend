<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\FarmSetting;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\BatchSchedule;
use App\Models\Permission;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarmAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Flock $flock;
    private string $token;
    private PoultryFeedType $feedType;

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
            'view statistics',
            'view flocks',
            'view feed inventories',
            'view feed inventory',
            'view medication inventory',
            'view vaccine inventory',
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
            'arrival_date' => Carbon::now()->subDays(10)->toDateString(),
            'arrival_age_days' => 0,
        ]);

        $this->feedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $poultryType->id,
            'name' => 'Starter',
            'description' => 'Test',
            'min_stock_level' => 50,
        ]);
    }

    public function test_low_stock_respects_min_stock_level_fallback(): void
    {
        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 20,
            'available_quantity' => 20,
            'unit_cost' => 5,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/alerts");

        $response->assertStatus(200);
        $items = collect($response->json('data.items'));
        $this->assertTrue($items->contains(fn ($item) => $item['category'] === 'low_stock'
            && str_contains($item['title'], 'Starter')));
        $this->assertGreaterThanOrEqual(1, $response->json('data.counts.warning') + $response->json('data.counts.critical'));
    }

    public function test_alerts_suppressed_when_low_stock_disabled(): void
    {
        FarmSetting::create([
            'farm_id' => $this->farm->id,
            'low_stock_alerts_enabled' => false,
        ]);

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 1,
            'available_quantity' => 1,
            'unit_cost' => 5,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/alerts");

        $response->assertStatus(200);
        $items = collect($response->json('data.items'));
        $this->assertFalse($items->contains(fn ($item) => $item['category'] === 'low_stock'));
        $this->assertFalse($response->json('data.settings.low_stock_alerts_enabled'));
    }

    public function test_per_flock_notifications_shape_unchanged(): void
    {
        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 5,
            'available_quantity' => 5,
            'unit_cost' => 5,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/notifications");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'upcoming_batch_items',
                    'low_stock' => ['medications', 'vaccines', 'feeds'],
                    'mortality_alerts',
                    'settings' => [
                        'schedule_reminder_days',
                        'low_stock_alerts_enabled',
                        'mortality_alert_percent',
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.low_stock.feeds'));
    }

    public function test_expiring_feed_produces_alert(): void
    {
        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 200,
            'available_quantity' => 200,
            'unit_cost' => 5,
            'status' => 'available',
            'expiry_date' => Carbon::now()->addDays(5)->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/alerts");

        $response->assertStatus(200);
        $items = collect($response->json('data.items'));
        $this->assertTrue($items->contains(fn ($item) => $item['category'] === 'expiring'));
    }

    public function test_low_stock_uses_remaining_quantity_not_original_stock(): void
    {
        // Remaining is low, but original stocked amount is still high.
        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 8,
            'available_quantity' => 500,
            'unit_cost' => 5,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/notifications");

        $response->assertStatus(200);
        $feeds = collect($response->json('data.low_stock.feeds'));
        $this->assertCount(1, $feeds);
        $this->assertEquals(8.0, (float) $feeds->first()['quantity']);
    }

    public function test_closed_feed_inventory_is_not_flagged_as_low_stock(): void
    {
        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 0,
            'available_quantity' => 100,
            'unit_cost' => 5,
            'status' => 'closed',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/notifications");

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data.low_stock.feeds'));
    }

    public function test_low_stock_falls_back_when_min_stock_level_is_zero(): void
    {
        $this->feedType->update(['min_stock_level' => 0]);

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 8,
            'available_quantity' => 100,
            'unit_cost' => 5,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/alerts");

        $response->assertStatus(200);
        $items = collect($response->json('data.items'));
        $this->assertTrue($items->contains(fn ($item) => $item['category'] === 'low_stock'
            && str_contains($item['title'], 'Starter')));
    }

    public function test_dashboard_includes_alerts_payload(): void
    {
        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 5,
            'available_quantity' => 5,
            'unit_cost' => 5,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/dashboard?preset=30d");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'alerts' => [
                        'counts' => ['critical', 'warning', 'info'],
                        'items',
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.alerts.items'));
    }

    public function test_depleted_expired_feed_does_not_produce_expiry_alert(): void
    {
        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 0,
            'available_quantity' => 100,
            'unit_cost' => 5,
            'status' => 'depleted',
            'expiry_date' => Carbon::now()->subDays(3)->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/alerts");

        $response->assertStatus(200);
        $items = collect($response->json('data.items'));
        $this->assertFalse($items->contains(fn ($item) => $item['category'] === 'expiring'));
    }

    public function test_upcoming_items_use_placement_age_and_surface_due_items(): void
    {
        $this->flock->update([
            'arrival_date' => Carbon::today()->subDays(12)->toDateString(),
            'arrival_age_days' => 1,
        ]);

        $schedule = Schedule::create([
            'schedule_type' => 'vaccination',
            'poultry_type_id' => $this->flock->poultry_type_id,
            'type' => 'user',
            'farm_id' => $this->farm->id,
            'name' => 'Broiler Vaccination',
            'description' => 'Test',
        ]);

        // Current age = 1 + 12 = 13. Item due at age 14 → tomorrow.
        ScheduleItem::create([
            'schedule_id' => $schedule->id,
            'age_days' => 14,
            'name' => 'Tomorrow Vaccine',
            'dose' => 1,
        ]);

        // Item due at age 10 → 3 days overdue (within lookback).
        ScheduleItem::create([
            'schedule_id' => $schedule->id,
            'age_days' => 10,
            'name' => 'Overdue Vaccine',
            'dose' => 1,
        ]);

        // Far future item should not appear in the default 7-day window.
        ScheduleItem::create([
            'schedule_id' => $schedule->id,
            'age_days' => 40,
            'name' => 'Far Future Vaccine',
            'dose' => 1,
        ]);

        BatchSchedule::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'schedule_id' => $schedule->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/notifications");

        $response->assertStatus(200);
        $upcoming = collect($response->json('data.upcoming_batch_items'));

        $titles = $upcoming->pluck('title')->all();
        $this->assertContains('Tomorrow Vaccine', $titles);
        $this->assertContains('Overdue Vaccine', $titles);
        $this->assertNotContains('Far Future Vaccine', $titles);

        $tomorrow = $upcoming->firstWhere('title', 'Tomorrow Vaccine');
        $overdue = $upcoming->firstWhere('title', 'Overdue Vaccine');

        $this->assertSame(1, (int) $tomorrow['days_until']);
        $this->assertLessThan(0, (int) $overdue['days_until']);
        $this->assertSame('overdue', $overdue['status']);
        $this->assertSame(-3, (int) $overdue['days_until']);
        $this->assertSame('vaccination', $tomorrow['type']);
        $this->assertArrayHasKey('schedule_item_id', $tomorrow);
        $this->assertArrayHasKey('batch_schedule_id', $tomorrow);
        $this->assertArrayHasKey('age_days', $tomorrow);
        $this->assertSame(14, (int) $tomorrow['age_days']);
        $this->assertArrayHasKey('settings', $response->json('data'));
    }
}
