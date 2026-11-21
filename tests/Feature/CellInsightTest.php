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
use App\Http\Middleware\EnsureAiEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CellInsightTest extends TestCase
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


    private function insightsUrl(string $suffix = ''): string
    {
        return "/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/egg-cell-insights{$suffix}";
    }

    /** Save one by-cell round. $eggs maps labels to eggs, or to [eggs, broken]. */
    private function round(string $date, array $eggs): void
    {
        $cells = [];
        foreach ($eggs as $label => $v) {
            [$e, $b] = is_array($v) ? $v : [$v, 0];
            $cells[] = ['cell_id' => $this->cellId($label), 'eggs' => $e, 'broken' => $b];
        }
        $this->api()->postJson($this->collectionsUrl(), [
            'date' => $date,
            'entry_mode' => 'cell',
            'cells' => $cells,
        ])->assertStatus(201);
    }

    private function days(int $n): string
    {
        return now()->subDays($n)->toDateString();
    }

    private function seedThreeDays(): void
    {
        $this->generateLayout();
        // Day 1: A1 lays 1, A2 lays 2, A3 lays 2, A4 nothing, B1 lays 4 ...
        $this->round($this->days(3), ['A1' => 1, 'A2' => 2, 'A3' => 2, 'A5' => 4, 'B1' => 4, 'B2' => 4]);
        $this->round($this->days(2), ['A1' => 1, 'A2' => 2, 'A3' => 2, 'A5' => 4, 'B1' => 4, 'B2' => 4]);
        // Today (skipping a calendar day on purpose): A1 1->2, A2 2->3, A3 2->1, A5 4->0, A4 first egg,
        // B1 lays 5 from 4 birds, B2 2 of 4 broken. B3..B5 never laid.
        $this->round($this->days(0), ['A1' => 2, 'A2' => 3, 'A3' => 1, 'A4' => 1, 'B1' => 5, 'B2' => [4, 2]]);
    }

    private function labels(array $list): array
    {
        return array_column($list, 'label');
    }

    public function test_classifies_day_on_day_cell_changes(): void
    {
        $this->seedThreeDays();

        $data = $this->api()->getJson($this->insightsUrl().'?date='.$this->days(0))
            ->assertOk()->json('data');

        $this->assertTrue($data['has_data']);
        // Compared with the previous recorded day, not yesterday's calendar date.
        $this->assertSame($this->days(2), $data['previous_date']);

        $e = $data['events'];
        $this->assertSame(['A4'], $this->labels($e['first_egg']));
        $this->assertEqualsCanonicalizing(['A1', 'A2', 'B1'], $this->labels($e['increase']));
        $this->assertSame('1 → 2', collect($e['increase'])->firstWhere('label', 'A1')['detail']);
        $this->assertSame(['A3'], $this->labels($e['decrease']));
        $this->assertSame(['A5'], $this->labels($e['stopped']));
        $this->assertSame(['B1'], $this->labels($e['over_capacity']));
        $this->assertSame(['B2'], $this->labels($e['high_broken']));
        $this->assertEqualsCanonicalizing(['B3', 'B4', 'B5'], $this->labels($e['never_laid']));

        $s = $data['summary'];
        $this->assertSame(16, $s['eggs']);
        $this->assertSame(17, $s['previous_eggs']);
        $this->assertSame(-1, $s['change']);
        $this->assertSame(6, $s['cells_laying']);
        $this->assertSame(1, $s['counts']['first_egg']);
        $this->assertFalse($s['possibly_incomplete']);

        $rowA = collect($data['rows'])->firstWhere('row_code', 'A');
        $this->assertSame(7, $rowA['eggs']);
        $this->assertSame(9, $rowA['previous_eggs']);
        $this->assertSame(3, $rowA['up']);
        $this->assertSame(2, $rowA['down']);

        $this->assertCount(3, $data['trend']);
        $this->assertSame([17, 17, 16], array_column($data['trend'], 'eggs'));

        $grid = collect($data['houses'][0]['rows'])->firstWhere('code', 'A')['cells'];
        $this->assertSame('first_egg', collect($grid)->firstWhere('label', 'A4')['status']);
    }

    public function test_silent_cell_after_several_empty_days(): void
    {
        $this->generateLayout();
        $this->round($this->days(4), ['A1' => 3, 'A2' => 1]);
        $this->round($this->days(3), ['A2' => 1]);
        $this->round($this->days(2), ['A2' => 1]);
        $this->round($this->days(1), ['A2' => 1]);

        $data = $this->api()->getJson($this->insightsUrl().'?date='.$this->days(1).'&silent_days=3')
            ->assertOk()->json('data');

        $silent = collect($data['events']['silent'])->firstWhere('label', 'A1');
        $this->assertNotNull($silent);
        $this->assertSame('no eggs for 3 recorded days', $silent['detail']);
        $this->assertSame([], $this->labels($data['events']['stopped']));
    }

    public function test_row_added_later_is_not_judged_on_days_before_it_had_entries(): void
    {
        $this->generateLayout();
        $this->round($this->days(2), ['A1' => 2]);
        $this->round($this->days(1), ['A1' => 2]);
        $this->api()->postJson($this->layoutUrl('/rows'), ['code' => 'C', 'cell_count' => 2])->assertStatus(201);
        PoultryHouseCell::whereHas('row', fn ($q) => $q->where('code', 'C'))->update(['flock_id' => $this->flock->id]);
        $this->round($this->days(0), ['A1' => 2, 'C1' => 3]);

        $grid = collect($this->api()->getJson($this->insightsUrl().'?date='.$this->days(0))->json('data.houses.0.rows'))
            ->firstWhere('code', 'C')['cells'];

        $this->assertSame('no_history', $grid[0]['status']);
        $this->assertNull($grid[0]['previous_eggs']);
    }

    public function test_flags_a_day_that_looks_unfinished(): void
    {
        $this->generateLayout();
        $this->round($this->days(1), ['A1' => 2, 'A2' => 2, 'A3' => 2, 'A4' => 2, 'B1' => 2, 'B2' => 2]);
        $this->round($this->days(0), ['A1' => 2]);

        $data = $this->api()->getJson($this->insightsUrl().'?date='.$this->days(0))->json('data');
        $this->assertTrue($data['summary']['possibly_incomplete']);
    }

    public function test_no_data_on_a_day_without_cell_rounds(): void
    {
        $this->generateLayout();
        $this->round($this->days(2), ['A1' => 2]);

        $data = $this->api()->getJson($this->insightsUrl().'?date='.$this->days(0))->assertOk()->json('data');
        $this->assertFalse($data['has_data']);
        $this->assertSame($this->days(2), $data['latest_recorded_date']);
    }

    public function test_ai_summary_reads_the_analysis(): void
    {
        $this->seedThreeDays();
        config(['llm.provider' => 'openai', 'llm.openai.api_key' => 'test', 'llm.openai.base_url' => 'https://llm.test']);
        Http::fake([
            'llm.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'headline' => 'A4 started laying',
                'summary' => 'Small dip overall.',
                'positives' => ['A4 laid its first egg'],
                'concerns' => ['A5 stopped'],
                'actions' => [['priority' => 'high', 'action' => 'Check A5', 'cells' => ['A5'], 'reason' => 'stopped']],
                'watch_cells' => [['cell' => 'B1', 'reason' => 'more eggs than birds']],
            ])]]]]),
        ]);

        $data = $this->withoutMiddleware(EnsureAiEntitlement::class)
            ->api()->postJson($this->insightsUrl('/ai'), ['date' => $this->days(0)])
            ->assertOk()->json('data');

        $this->assertTrue($data['ai_available']);
        $this->assertSame('A4 started laying', $data['ai_insights']['headline']);

        Http::assertSent(function ($request) {
            $prompt = $request['messages'][1]['content'] ?? '';

            return str_contains($prompt, '"A4 first egg"') && str_contains($prompt, '"A5 4 → 0"');
        });
    }

    public function test_ai_summary_needs_a_recorded_day(): void
    {
        $this->generateLayout();

        $this->withoutMiddleware(EnsureAiEntitlement::class)
            ->api()->postJson($this->insightsUrl('/ai'), ['date' => $this->days(0)])
            ->assertStatus(422);
    }
}
