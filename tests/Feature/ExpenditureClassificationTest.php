<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockExpenditure;
use App\Models\FlockStage;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\User;
use App\Services\ExpenditureClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenditureClassificationTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private Flock $flock;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $user->id,
            'country_id' => $country->id,
        ]);

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

    public function test_manual_other_expenditure_is_reclassified_from_description(): void
    {
        $expenditure = FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'other',
            'amount' => 2500,
            'description' => 'Farmhand weekly wages',
            'date' => now()->toDateString(),
            'source_type' => 'manual',
        ]);

        $result = app(ExpenditureClassificationService::class)->reclassify($expenditure->fresh());

        $this->assertTrue($result['category_changed']);
        $this->assertSame('labour', $expenditure->fresh()->category);
    }

    public function test_manual_other_birds_expenditure_is_reclassified_to_chicks(): void
    {
        $expenditure = FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'other',
            'amount' => 185000,
            'description' => 'Purchase of birds for new batch',
            'date' => now()->toDateString(),
            'source_type' => 'manual',
        ]);

        $result = app(ExpenditureClassificationService::class)->reclassify($expenditure->fresh());

        $this->assertTrue($result['category_changed']);
        $this->assertSame('chicks', $expenditure->fresh()->category);
    }

    public function test_birds_keyword_alone_maps_to_chicks(): void
    {
        $expenditure = FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'other',
            'amount' => 95000,
            'description' => 'birds',
            'date' => now()->toDateString(),
            'source_type' => 'manual',
        ]);

        app(ExpenditureClassificationService::class)->reclassify($expenditure->fresh());

        $this->assertSame('chicks', $expenditure->fresh()->category);
    }

    public function test_auto_feed_expenditure_gets_enriched_description(): void
    {
        $poultryType = PoultryType::first();
        $feedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $poultryType->id,
            'name' => 'Grower Feed',
            'description' => 'Test feed',
        ]);
        $inventory = PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $feedType->id,
            'quantity' => 100,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'FEED-001',
        ]);
        $usage = PoultryFeedUsage::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_inventory_id' => $inventory->id,
            'poultry_feed_type_id' => $feedType->id,
            'flock_id' => $this->flock->id,
            'quantity' => 10,
            'unit_cost' => 2.5,
            'usage_date' => now()->toDateString(),
            'created_by' => User::factory()->create()->id,
        ]);

        $expenditure = FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'feed',
            'amount' => 25,
            'description' => 'Feed usage',
            'date' => now()->toDateString(),
            'source_type' => 'feed_usage',
            'source_id' => $usage->id,
        ]);

        $result = app(ExpenditureClassificationService::class)->reclassify($expenditure->fresh());

        $this->assertFalse($result['category_changed']);
        $this->assertTrue($result['description_changed']);
        $this->assertStringContainsString('Grower Feed', (string) $expenditure->fresh()->description);
    }

    public function test_manual_non_other_category_is_not_reclassified(): void
    {
        $expenditure = FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'transport',
            'amount' => 1200,
            'description' => 'Farmhand wages',
            'date' => now()->toDateString(),
            'source_type' => 'manual',
        ]);

        $result = app(ExpenditureClassificationService::class)->reclassify($expenditure->fresh());

        $this->assertFalse($result['category_changed']);
        $this->assertSame('transport', $expenditure->fresh()->category);
    }

    public function test_reclassify_all_reports_stats(): void
    {
        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'other',
            'amount' => 800,
            'description' => 'Diesel for delivery truck',
            'date' => now()->toDateString(),
            'source_type' => 'manual',
        ]);

        FlockExpenditure::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'category' => 'other',
            'amount' => 300,
            'description' => 'Misc',
            'date' => now()->toDateString(),
            'source_type' => 'manual',
        ]);

        $stats = app(ExpenditureClassificationService::class)->reclassifyAll();

        $this->assertSame(2, $stats['scanned']);
        $this->assertGreaterThanOrEqual(1, $stats['category_updated']);
    }
}
