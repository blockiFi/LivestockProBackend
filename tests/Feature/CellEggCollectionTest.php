<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryHouse;
use App\Models\PoultryHouseCell;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CellEggCollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Farm $farm;

    private string $token;

    private PoultryHouse $house;

    private Flock $flock;

    private Flock $otherFlock;

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
            'create flock egg reports',
            'update flock egg reports',
            'delete flock egg reports',
            'view flock egg reports',
            'manage poultry houses',
            'view poultry houses',
            'manage flocks',
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));

        $ownerRole = Role::create(['name' => 'owner', 'guard_name' => 'api', 'farm_id' => $this->farm->id]);
        $ownerRole->givePermissionTo($permissions);

        $this->farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
        $this->user->assignRole($ownerRole);

        $poultryType = PoultryType::factory()->create(['name' => 'Layer']);
        $flockStage = FlockStage::factory()->create(['poultry_type_id' => $poultryType->id]);
        $this->house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $poultryType->id,
        ]);

        $flockAttrs = [
            'farm_id' => $this->farm->id,
            'house_id' => $this->house->id,
            'poultry_type_id' => $poultryType->id,
            'flock_stage_id' => $flockStage->id,
            'arrival_date' => now()->subDays(30)->toDateString(),
            'status' => 'active',
        ];
        $this->flock = Flock::factory()->create($flockAttrs + ['quantity' => 40]);
        $this->otherFlock = Flock::factory()->create($flockAttrs + ['quantity' => 40]);
    }

    private function api()
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function layoutUrl(string $suffix = ''): string
    {
        return "/api/poultry-houses/{$this->farm->id}/{$this->house->id}/egg-layout{$suffix}";
    }

    private function collectionsUrl(?Flock $flock = null, string $suffix = ''): string
    {
        $flock ??= $this->flock;

        return "/api/farms/{$this->farm->id}/flocks/{$flock->id}/egg-collections{$suffix}";
    }

    /** Rows A and B, 5 cells each, 4 birds per cell, all assigned to the main flock. */
    private function generateLayout(): array
    {
        return $this->api()->postJson($this->layoutUrl('/generate'), [
            'codes' => ['A', 'B'],
            'cells_per_row' => 5,
            'birds_per_cell' => 4,
            'flock_id' => $this->flock->id,
        ])->assertStatus(201)->json('data');
    }

    private function cellId(string $label): int
    {
        $row = substr($label, 0, 1);
        $number = (int) substr($label, 1);

        return PoultryHouseCell::whereHas('row', fn ($q) => $q->where('house_id', $this->house->id)->where('code', $row))
            ->where('cell_number', $number)
            ->value('id');
    }

    public function test_generate_creates_rows_and_cells_with_birds_and_flock(): void
    {
        $layout = $this->generateLayout();

        $this->assertSame('cell', $layout['house']['egg_collection_mode']);
        $this->assertSame(4, $layout['house']['birds_per_cell']);
        $this->assertCount(2, $layout['rows']);
        $this->assertSame('A1', $layout['rows'][0]['cells'][0]['label']);
        $this->assertSame(['rows' => 2, 'cells' => 10, 'birds' => 40, 'unassigned_cells' => 0], $layout['summary']);
        $this->assertSame(10, PoultryHouseCell::where('flock_id', $this->flock->id)->count());
    }

    public function test_cells_can_be_edited_and_assigned_to_a_second_flock(): void
    {
        $this->generateLayout();
        $rowB = $this->house->eggRows()->where('code', 'B')->first();

        $this->api()->postJson($this->layoutUrl('/assign'), [
            'flock_id' => $this->otherFlock->id,
            'row_id' => $rowB->id,
            'from' => 1,
            'to' => 3,
        ])->assertOk()->assertJsonPath('data.assigned_cells', 3);

        $this->api()->putJson($this->layoutUrl('/cells'), [
            'cells' => [['id' => $this->cellId('A1'), 'birds' => 3]],
        ])->assertOk();

        $this->assertSame(3, PoultryHouseCell::find($this->cellId('A1'))->birds);
        $this->assertSame(3, PoultryHouseCell::where('flock_id', $this->otherFlock->id)->count());

        $sheet = $this->api()->getJson("/api/farms/{$this->farm->id}/flocks/{$this->otherFlock->id}/egg-sheet")
            ->assertOk()->json('data');
        $this->assertSame(3, $sheet['total_cells']);
        $this->assertSame('B1', $sheet['houses'][0]['rows'][0]['cells'][0]['label']);
    }

    public function test_shrinking_a_row_keeps_cells_with_history(): void
    {
        $this->generateLayout();
        $rowA = $this->house->eggRows()->where('code', 'A')->first();

        $this->api()->postJson($this->collectionsUrl(), [
            'date' => now()->toDateString(),
            'entry_mode' => 'cell',
            'cells' => [['cell_id' => $this->cellId('A5'), 'eggs' => 2]],
        ])->assertStatus(201);

        $this->api()->putJson($this->layoutUrl("/rows/{$rowA->id}"), ['cell_count' => 3])->assertOk();

        $cells = PoultryHouseCell::where('row_id', $rowA->id)->orderBy('cell_number')->get()->keyBy('cell_number');
        $this->assertSame([1, 2, 3, 5], $cells->keys()->all()); // A4 had no history and was removed
        $this->assertFalse($cells[5]->is_active);
        $this->assertTrue($cells[3]->is_active);
    }

    public function test_cell_round_rolls_up_to_daily_report(): void
    {
        $this->generateLayout();
        $date = now()->toDateString();

        $response = $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'collected_at' => '07:30',
            'entry_mode' => 'cell',
            'cells' => [
                ['cell_id' => $this->cellId('A1'), 'eggs' => 4, 'broken' => 1],
                ['cell_id' => $this->cellId('A2'), 'eggs' => 3],
                ['cell_id' => $this->cellId('B1'), 'eggs' => 2],
            ],
            'eggs_broken_extra' => 1,
            'average_egg_weight' => 60,
        ])->assertStatus(201);

        $response->assertJsonPath('data.report.eggs_collected', 9)
            ->assertJsonPath('data.report.eggs_broken', 2)
            ->assertJsonPath('data.report.eggs_good', 7)
            ->assertJsonPath('data.report.entry_mode', 'cell')
            ->assertJsonPath('data.report.row_totals.A', 7)
            ->assertJsonPath('data.report.row_totals.B', 2)
            ->assertJsonPath('data.collection.collected_at', '07:30');

        $report = PoultryFlockEggReport::where('flock_id', $this->flock->id)->first();
        $this->assertSame(9, $report->eggs_collected);
        $this->assertEquals(22.5, (float) $report->production_percentage); // 9 / 40 birds
    }

    public function test_rounds_add_up_and_keep_an_existing_total(): void
    {
        $this->generateLayout();
        $date = now()->toDateString();

        // A total typed in the old way first.
        $this->api()->postJson("/api/farms/{$this->farm->id}/flock-egg-reports", [
            'flock_id' => $this->flock->id,
            'date' => $date,
            'eggs_collected' => 10,
            'eggs_broken' => 1,
        ])->assertStatus(201);

        $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'entry_mode' => 'cell',
            'cells' => [['cell_id' => $this->cellId('A1'), 'eggs' => 3]],
        ])->assertStatus(201);

        $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'entry_mode' => 'total',
            'eggs_collected' => 5,
        ])->assertStatus(201)
            ->assertJsonPath('data.collection.round_no', 3)
            ->assertJsonPath('data.report.eggs_collected', 18)
            ->assertJsonPath('data.report.eggs_broken', 1);

        $this->assertSame(1, PoultryFlockEggReport::where('flock_id', $this->flock->id)->count());

        $sheet = $this->api()->getJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/egg-sheet?date={$date}")
            ->assertOk()->json('data');
        $this->assertSame(3, $sheet['houses'][0]['rows'][0]['cells'][0]['recorded_eggs']);
        $this->assertCount(3, $sheet['report']['rounds']);
    }

    public function test_cell_validation_and_over_capacity_warning(): void
    {
        $this->generateLayout();
        $date = now()->toDateString();
        $this->api()->postJson($this->layoutUrl('/assign'), [
            'flock_id' => $this->otherFlock->id,
            'row_ids' => [$this->house->eggRows()->where('code', 'B')->value('id')],
        ])->assertOk();

        // Broken above eggs in a cell.
        $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'entry_mode' => 'cell',
            'cells' => [['cell_id' => $this->cellId('A1'), 'eggs' => 1, 'broken' => 2]],
        ])->assertStatus(422);

        // A cell that belongs to another flock.
        $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'entry_mode' => 'cell',
            'cells' => [['cell_id' => $this->cellId('B1'), 'eggs' => 1]],
        ])->assertStatus(422);
        $this->assertSame(0, PoultryFlockEggReport::count());

        // More eggs in a day than birds in the cell: saved, with a warning.
        $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'entry_mode' => 'cell',
            'cells' => [['cell_id' => $this->cellId('A1'), 'eggs' => 3]],
        ])->assertStatus(201)->assertJsonCount(0, 'data.warnings');

        $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'entry_mode' => 'cell',
            'cells' => [['cell_id' => $this->cellId('A1'), 'eggs' => 2]],
        ])->assertStatus(201)
            ->assertJsonPath('data.warnings.0', 'A1 has 5 eggs today from 4 birds.');
    }

    public function test_round_update_and_delete_recalculate_report(): void
    {
        $this->generateLayout();
        $date = now()->toDateString();

        $first = $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'entry_mode' => 'cell',
            'cells' => [['cell_id' => $this->cellId('A1'), 'eggs' => 4]],
        ])->json('data.collection.id');
        $second = $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'entry_mode' => 'total',
            'eggs_collected' => 6,
        ])->json('data.collection.id');

        $this->api()->putJson($this->collectionsUrl(null, "/{$first}"), [
            'entry_mode' => 'cell',
            'cells' => [['cell_id' => $this->cellId('A1'), 'eggs' => 2], ['cell_id' => $this->cellId('A2'), 'eggs' => 4]],
        ])->assertOk()->assertJsonPath('data.report.eggs_collected', 12);

        $this->api()->deleteJson($this->collectionsUrl(null, "/{$second}"))
            ->assertOk()->assertJsonPath('data.report.eggs_collected', 6);

        $this->api()->deleteJson($this->collectionsUrl(null, "/{$first}"))
            ->assertOk()->assertJsonPath('data.report', null);
        $this->assertSame(0, PoultryFlockEggReport::count());
    }

    public function test_old_report_endpoint_cannot_overwrite_round_totals(): void
    {
        $this->generateLayout();
        $reportId = $this->api()->postJson($this->collectionsUrl(), [
            'date' => now()->toDateString(),
            'entry_mode' => 'total',
            'eggs_collected' => 6,
        ])->json('data.report.id');

        $this->api()->putJson("/api/farms/{$this->farm->id}/flock-egg-reports/{$reportId}", [
            'eggs_collected' => 99,
        ])->assertStatus(422);

        $this->api()->putJson("/api/farms/{$this->farm->id}/flock-egg-reports/{$reportId}", [
            'notes' => 'Checked',
        ])->assertOk()->assertJsonPath('data.notes', 'Checked');

        $this->assertSame(6, PoultryFlockEggReport::find($reportId)->eggs_collected);
    }

    public function test_daily_record_does_not_overwrite_round_managed_report(): void
    {
        $this->generateLayout();
        $date = now()->toDateString();

        $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'entry_mode' => 'cell',
            'cells' => [['cell_id' => $this->cellId('A1'), 'eggs' => 4]],
        ])->assertStatus(201);

        $this->api()->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
            'flock_id' => $this->flock->id,
            'date' => $date,
            'eggs_collected' => 120,
        ])->assertStatus(201);

        $this->assertSame(1, PoultryFlockEggReport::where('flock_id', $this->flock->id)->count());
        $this->assertSame(4, PoultryFlockEggReport::where('flock_id', $this->flock->id)->first()->eggs_collected);
    }

    public function test_cell_performance_lists_weakest_cells_first(): void
    {
        $this->generateLayout();

        $this->api()->postJson($this->collectionsUrl(), [
            'date' => now()->toDateString(),
            'entry_mode' => 'cell',
            'cells' => [
                ['cell_id' => $this->cellId('A1'), 'eggs' => 4],
                ['cell_id' => $this->cellId('A2'), 'eggs' => 1],
            ],
        ])->assertStatus(201);

        $cells = $this->api()->getJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/egg-cell-performance")
            ->assertOk()->json('data.cells');

        $this->assertSame('A2', $cells[0]['label']);
        $this->assertEquals(25.0, $cells[0]['lay_rate']);
        $recorded = collect($cells)->firstWhere('label', 'A1');
        $this->assertEquals(100.0, $recorded['lay_rate']);
    }
}
