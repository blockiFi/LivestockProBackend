<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryMortalityReport;
use App\Models\SalesRecord;
use Database\Seeders\LayerDemo\LayerDemoContext;
use Database\Seeders\LayerDemoFarmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LayerDemoFarmSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_layer_demo_farm_seeder_creates_full_operational_dataset(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('LayerDemoFarmSeeder integration test requires MySQL (nested seeders use MySQL-specific SQL).');
        }

        $this->seed(LayerDemoFarmSeeder::class);

        $farm = Farm::where('name', LayerDemoContext::FARM_NAME)->first();
        $this->assertNotNull($farm);

        $flock = Flock::where('farm_id', $farm->id)->first();
        $this->assertNotNull($flock);
        $this->assertSame('active', $flock->status);
        $this->assertSame(LayerDemoContext::FLOCK_QUANTITY, $flock->quantity);

        $dailyCount = FlockDailyRecord::where('flock_id', $flock->id)->count();
        $this->assertGreaterThanOrEqual(390, $dailyCount);
        $this->assertLessThanOrEqual(401, $dailyCount);

        $this->assertGreaterThan(100, PoultryFlockEggReport::where('flock_id', $flock->id)->count());
        $this->assertGreaterThan(10, PoultryMortalityReport::where('flock_id', $flock->id)->count());
        $this->assertGreaterThanOrEqual(6, Customer::where('farm_id', $farm->id)->count());
        $this->assertGreaterThan(50, SalesRecord::where('flock_id', $flock->id)->count());
        $this->assertGreaterThan(10, FlockExpenditure::where('flock_id', $flock->id)->count());
        $this->assertGreaterThanOrEqual(1, FlockSale::where('flock_id', $flock->id)->count());
    }
}
