<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\PoultryType;
use App\Models\FlockDailyRecord;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryFeedType;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryHouse;
use App\Models\FlockStage;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Carbon\Carbon;

class PoultryStatisticsTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $farm;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a user and farm
        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();
        
        // Associate user with farm
        $this->user->update(['farm_id' => $this->farm->id]);
        
        // Create poultry types (without farm_id since it doesn't exist in schema)
        $layerType = PoultryType::factory()->create([
            'name' => 'Layer'
        ]);
        
        $broilerType = PoultryType::factory()->create([
            'name' => 'Broiler'
        ]);
        
        $brooderType = PoultryType::factory()->create([
            'name' => 'Brooder'
        ]);
        
        // Create flock stage
        $flockStage = FlockStage::factory()->create();
        
        // Create poultry house
        $poultryHouse = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id
        ]);
        
        // Create country for feed usage
        $country = Country::factory()->create();
        
        // Create flocks
        $layerFlock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $layerType->id,
            'house_id' => $poultryHouse->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 1000,
            'status' => 'active'
        ]);
        
        $broilerFlock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $broilerType->id,
            'house_id' => $poultryHouse->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 500,
            'status' => 'active'
        ]);
        
        $brooderFlock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $brooderType->id,
            'house_id' => $poultryHouse->id,
            'flock_stage_id' => $flockStage->id,
            'quantity' => 300,
            'status' => 'active'
        ]);
        
        // Create daily records for the last 7 days
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            
            // Layer flock daily record
            FlockDailyRecord::factory()->create([
                'flock_id' => $layerFlock->id,
                'farm_id' => $this->farm->id,
                'date' => $date,
                'mortality' => 2,
                'feed_consumed_kg' => 50 + ($i * 0.5),
                'eggs_collected' => 800 + ($i * 10),
                'avg_weight_grams' => 1500 + ($i * 100),
                'recorded_by' => $this->user->id
            ]);
            
            // Broiler flock daily record
            FlockDailyRecord::factory()->create([
                'flock_id' => $broilerFlock->id,
                'farm_id' => $this->farm->id,
                'date' => $date,
                'mortality' => 1,
                'feed_consumed_kg' => 25 + ($i * 0.3),
                'avg_weight_grams' => 2000 + ($i * 150),
                'recorded_by' => $this->user->id
            ]);
            
            // Brooder flock daily record
            FlockDailyRecord::factory()->create([
                'flock_id' => $brooderFlock->id,
                'farm_id' => $this->farm->id,
                'date' => $date,
                'mortality' => 1,
                'feed_consumed_kg' => 15 + ($i * 0.2),
                'avg_weight_grams' => 500 + ($i * 50),
                'recorded_by' => $this->user->id
            ]);
        }
        
        // Create feed usage records
        $feedType = PoultryFeedType::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Starter Feed'
        ]);
        
        $feedInventory = PoultryFeedInventory::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id
        ]);
        
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            
            PoultryFeedUsage::factory()->create([
                'farm_id' => $this->farm->id,
                'flock_id' => $layerFlock->id,
                'poultry_feed_inventory_id' => $feedInventory->id,
                'poultry_feed_type_id' => $feedType->id,
                'quantity' => 50 + ($i * 0.5),
                'unit_cost' => 2.5,
                'usage_date' => $date,
                'countries_id' => $country->id
            ]);
            
            PoultryFeedUsage::factory()->create([
                'farm_id' => $this->farm->id,
                'flock_id' => $broilerFlock->id,
                'poultry_feed_inventory_id' => $feedInventory->id,
                'poultry_feed_type_id' => $feedType->id,
                'quantity' => 25 + ($i * 0.3),
                'unit_cost' => 2.5,
                'usage_date' => $date,
                'countries_id' => $country->id
            ]);
            
            PoultryFeedUsage::factory()->create([
                'farm_id' => $this->farm->id,
                'flock_id' => $brooderFlock->id,
                'poultry_feed_inventory_id' => $feedInventory->id,
                'poultry_feed_type_id' => $feedType->id,
                'quantity' => 15 + ($i * 0.2),
                'unit_cost' => 2.5,
                'usage_date' => $date,
                'countries_id' => $country->id
            ]);
        }
    }

    public function test_can_get_poultry_statistics()
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/poultry-statistics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => [
                        'total_birds',
                        'total_flocks',
                        'active_flocks',
                        'date_range'
                    ],
                    'poultry_types',
                    'feed_consumption' => [
                        'total_feed_consumed_kg',
                        'total_feed_cost',
                        'average_daily_feed_kg',
                        'average_daily_feed_per_bird_kg',
                        'feed_conversion_ratio',
                        'daily_breakdown'
                    ],
                    'mortality' => [
                        'total_mortality',
                        'average_daily_mortality',
                        'average_mortality_rate_percent',
                        'mortality_reports_count',
                        'daily_breakdown'
                    ],
                    'egg_production' => [
                        'total_eggs_produced',
                        'average_daily_eggs',
                        'daily_breakdown'
                    ],
                    'weight_metrics',
                    'performance',
                    'financial',
                    'flock_details'
                ]
            ]);

        $data = $response->json('data');
        
        // Verify summary data
        $this->assertEquals(1800, $data['summary']['total_birds']); // 1000 + 500 + 300
        $this->assertEquals(3, $data['summary']['active_flocks']);
        
        // Verify poultry types
        $this->assertCount(3, $data['poultry_types']);
        
        // Verify feed consumption
        $this->assertGreaterThan(0, $data['feed_consumption']['total_feed_consumed_kg']);
        $this->assertGreaterThan(0, $data['feed_consumption']['total_feed_cost']);
        
        // Verify mortality
        $this->assertGreaterThan(0, $data['mortality']['total_mortality']);
        
        // Verify egg production
        $this->assertGreaterThan(0, $data['egg_production']['total_eggs_produced']);
    }

    public function test_can_get_poultry_statistics_with_date_range()
    {
        $startDate = Carbon::now()->subDays(3)->toDateString();
        $endDate = Carbon::now()->toDateString();
        
        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/poultry-statistics?start_date={$startDate}&end_date={$endDate}");

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertEquals($startDate, $data['summary']['date_range']['start_date']);
        $this->assertEquals($endDate, $data['summary']['date_range']['end_date']);
    }

    public function test_can_get_poultry_statistics_with_date_range_in_path()
    {
        $startDate = Carbon::now()->subDays(3)->toDateString();
        $endDate = Carbon::now()->toDateString();
        
        $encodedStartDate = urlencode($startDate);
        $encodedEndDate = urlencode($endDate);
        
        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/poultry-statistics/start_date={$encodedStartDate}/end_date={$encodedEndDate}");

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertEquals($startDate, $data['summary']['date_range']['start_date']);
        $this->assertEquals($endDate, $data['summary']['date_range']['end_date']);
    }

    public function test_can_get_poultry_statistics_with_invalid_date_range()
    {
        $startDate = Carbon::now()->toDateString();
        $endDate = Carbon::now()->subDays(3)->toDateString(); // End date before start date
        
        $encodedStartDate = urlencode($startDate);
        $encodedEndDate = urlencode($endDate);
        
        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/poultry-statistics/start_date={$encodedStartDate}/end_date={$encodedEndDate}");

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Start date cannot be after end date'
            ]);
    }

    public function test_unauthorized_user_cannot_access_poultry_statistics()
    {
        $unauthorizedUser = User::factory()->create();
        
        $response = $this->actingAs($unauthorizedUser)
            ->getJson("/api/farms/{$this->farm->id}/poultry-statistics");

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'User is not associated with any farm'
            ]);
    }

    public function test_user_without_permission_cannot_access_statistics()
    {
        // Create a user without the 'view statistics' permission
        $userWithoutPermission = User::factory()->create(['farm_id' => $this->farm->id]);
        
        $response = $this->actingAs($userWithoutPermission)
            ->getJson("/api/farms/{$this->farm->id}/poultry-statistics");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'You do not have permission to view poultry statistics'
            ]);
    }
} 