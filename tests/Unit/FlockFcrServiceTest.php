<?php

namespace Tests\Unit;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockStage;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryFlockWeightReport;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\PoultryFeedType;
use App\Models\User;
use App\Services\FlockFcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlockFcrServiceTest extends TestCase
{
    use RefreshDatabase;

    private Flock $flock;
    private FlockFcrService $service;
    private User $user;
    private int $feedTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $country = Country::factory()->create();
        $farm = Farm::factory()->create([
            'created_by' => $this->user->id,
            'country_id' => $country->id,
        ]);
        $poultryType = PoultryType::factory()->create();
        $house = PoultryHouse::factory()->create(['farm_id' => $farm->id]);
        $stage = FlockStage::factory()->create(['poultry_type_id' => $poultryType->id]);

        $this->flock = Flock::factory()->create([
            'farm_id' => $farm->id,
            'house_id' => $house->id,
            'poultry_type_id' => $poultryType->id,
            'flock_stage_id' => $stage->id,
            'quantity' => 1000,
        ]);

        $feedType = PoultryFeedType::create([
            'farm_id' => $farm->id,
            'type' => 'user',
            'poultry_type_id' => $poultryType->id,
            'name' => 'Grower Feed',
            'description' => 'Test feed',
        ]);
        $this->feedTypeId = $feedType->id;

        $this->service = app(FlockFcrService::class);
    }

    public function test_computes_fcr_from_weight_report_in_kg(): void
    {
        PoultryFeedUsage::create([
            'farm_id' => $this->flock->farm_id,
            'flock_id' => $this->flock->id,
            'poultry_feed_type_id' => $this->feedTypeId,
            'usage_date' => now()->subDays(5)->toDateString(),
            'quantity' => 2500,
            'created_by' => $this->user->id,
        ]);

        PoultryFlockWeightReport::create([
            'farm_id' => $this->flock->farm_id,
            'flock_id' => $this->flock->id,
            'average_weight' => 2.5,
            'number_of_birds' => 1000,
            'report_date' => now()->toDateString(),
            'recorded_by' => $this->user->id,
        ]);

        $fcr = $this->service->compute($this->flock);

        $this->assertNotNull($fcr);
        $this->assertEquals(1.02, $fcr);
    }

    public function test_returns_null_when_weight_gain_is_non_positive(): void
    {
        PoultryFlockWeightReport::create([
            'farm_id' => $this->flock->farm_id,
            'flock_id' => $this->flock->id,
            'average_weight' => 0.03,
            'number_of_birds' => 1000,
            'report_date' => now()->toDateString(),
            'recorded_by' => $this->user->id,
        ]);

        $this->assertNull($this->service->compute($this->flock));
    }

    public function test_sums_daily_feed_from_either_column(): void
    {
        FlockDailyRecord::create([
            'farm_id' => $this->flock->farm_id,
            'flock_id' => $this->flock->id,
            'date' => now()->subDays(3)->toDateString(),
            'feed_consumption_kg' => 2000,
            'mortality' => 0,
            'recorded_by' => $this->user->id,
        ]);

        PoultryFlockWeightReport::create([
            'farm_id' => $this->flock->farm_id,
            'flock_id' => $this->flock->id,
            'average_weight' => 2.0,
            'number_of_birds' => 1000,
            'report_date' => now()->toDateString(),
            'recorded_by' => $this->user->id,
        ]);

        $fcr = $this->service->compute($this->flock);

        $this->assertNotNull($fcr);
        $this->assertEquals(1.02, $fcr);
    }
}
