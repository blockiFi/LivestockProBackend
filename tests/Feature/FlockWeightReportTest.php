<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\FarmSubscription;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryFlockWeightReport;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FlockWeightReportTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $user;
    private Farm $farm;
    private string $token;
    private Flock $flock;
    private PoultryHouse $house;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $country = Country::factory()->create();
        $this->farm = Farm::factory()->create([
            'created_by' => $this->user->id,
            'country_id' => $country->id,
        ]);

        $role = Role::create(['name' => 'owner', 'guard_name' => 'api', 'farm_id' => $this->farm->id]);
        foreach ([
            'manage flocks',
            'view flock weight reports',
            'create flock weight reports',
            'update flock weight reports',
            'delete flock weight reports',
        ] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'api', $this->farm->id));
        }

        $this->farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
        $this->user->assignRole($role);

        $poultryType = PoultryType::factory()->create(['name' => 'Broiler']);
        $stage = FlockStage::factory()->create(['poultry_type_id' => $poultryType->id]);
        $this->house = PoultryHouse::factory()->create([
            'farm_id' => $this->farm->id,
            'poultry_type_id' => $poultryType->id,
        ]);

        $this->flock = Flock::factory()->create([
            'farm_id' => $this->farm->id,
            'house_id' => $this->house->id,
            'poultry_type_id' => $poultryType->id,
            'flock_stage_id' => $stage->id,
            'quantity' => 5000,
            'arrival_date' => now()->subDays(20)->toDateString(),
            'arrival_age_days' => 1,
            'status' => 'active',
        ]);
    }

    private function api()
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/farms/{$this->farm->id}/flock-weight-reports{$suffix}";
    }

    private function enableAi(): void
    {
        $plan = SubscriptionPlan::create([
            'slug' => 'premium-ai-test',
            'name' => 'Premium',
            'price_kobo' => 0,
            'ai_enabled' => true,
            'max_users' => null,
            'max_active_flocks' => null,
            'sort_order' => 3,
        ]);

        FarmSubscription::create([
            'farm_id' => $this->farm->id,
            'subscription_plan_id' => $plan->id,
            'status' => FarmSubscription::STATUS_ACTIVE,
            'trial_ends_at' => null,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    public function test_store_with_samples_computes_statistics_and_mirrors_legacy_columns(): void
    {
        $date = now()->toDateString();

        $response = $this->api()->postJson($this->url(), [
            'flock_id' => $this->flock->id,
            'report_date' => $date,
            'house_id' => $this->house->id,
            'pen_label' => 'Pen 2',
            'sex' => 'mixed',
            'weighing_method' => 'manual_scale',
            'weighed_at_time' => '07:30',
            'target_weight_g' => 1250,
            'uniformity_band_percent' => 10,
            'samples' => [
                ['weight_g' => 1000],
                ['weight_g' => 1100],
                ['weight_g' => 900],
                ['weight_g' => 1200],
                ['weight_g' => 800],
                ['weight_g' => 5000, 'excluded' => true],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.data_source', 'manual')
            ->assertJsonPath('data.sample_size', 5)
            ->assertJsonPath('data.age_days', 21)
            ->assertJsonCount(6, 'data.samples');

        $report = PoultryFlockWeightReport::firstOrFail();
        $this->assertEqualsWithDelta(1000.0, $report->average_weight_g, 0.01);
        $this->assertEqualsWithDelta(1000.0, $report->median_weight_g, 0.01);
        $this->assertEqualsWithDelta(158.11, $report->std_dev_g, 0.01);
        $this->assertEqualsWithDelta(15.81, $report->cv_percent, 0.01);
        $this->assertEqualsWithDelta(60.0, $report->uniformity_percent, 0.01);
        $this->assertSame(3, (int) $report->birds_within_band);
        $this->assertEqualsWithDelta(-250.0, $report->deviation_from_target_g, 0.01);
        $this->assertEqualsWithDelta(-20.0, $report->deviation_from_target_percent, 0.01);

        // Legacy kg columns still feed FCR / metrics services.
        $this->assertEquals('1.00', $report->average_weight);
        $this->assertEquals('0.80', $report->min_weight);
        $this->assertEquals('1.20', $report->max_weight);
        $this->assertSame(5000, (int) $report->number_of_birds);

        $this->assertDatabaseHas('poultry_flock_weight_samples', [
            'weight_report_id' => $report->id,
            'sequence' => 6,
            'excluded' => true,
        ]);
    }

    public function test_store_with_samples_requires_target_weight(): void
    {
        $this->api()->postJson($this->url(), [
            'flock_id' => $this->flock->id,
            'report_date' => now()->toDateString(),
            'samples' => [['weight_g' => 1000], ['weight_g' => 1100]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('poultry_flock_weight_reports', 0);
    }

    public function test_daily_gain_uses_previous_session(): void
    {
        $this->api()->postJson($this->url(), [
            'flock_id' => $this->flock->id,
            'report_date' => now()->subDays(7)->toDateString(),
            'target_weight_g' => 600,
            'samples' => [['weight_g' => 600], ['weight_g' => 640], ['weight_g' => 620]],
        ])->assertStatus(201);

        $this->api()->postJson($this->url(), [
            'flock_id' => $this->flock->id,
            'report_date' => now()->toDateString(),
            'target_weight_g' => 1000,
            'samples' => [['weight_g' => 960], ['weight_g' => 1000], ['weight_g' => 1040]],
        ])->assertStatus(201)
            ->assertJsonPath('data.daily_gain_g', 54.29);
    }

    public function test_summary_only_payload_is_still_accepted(): void
    {
        $response = $this->api()->postJson($this->url(), [
            'flock_id' => $this->flock->id,
            'report_date' => now()->toDateString(),
            'average_weight' => 1.5,
            'min_weight' => 1.3,
            'max_weight' => 1.7,
            'sample_size' => 50,
            'number_of_birds' => 4900,
            'target_weight_g' => 1600,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.data_source', 'summary_only');

        $report = PoultryFlockWeightReport::firstOrFail();
        $this->assertEqualsWithDelta(1500.0, $report->average_weight_g, 0.01);
        $this->assertEqualsWithDelta(-6.25, $report->deviation_from_target_percent, 0.01);
        $this->assertSame(0, $report->samples()->count());

        // SD ≈ 400 / d2(50)=4.498 → 88.9 g; share of a normal within ±150 g ≈ 90.8%.
        $this->assertSame('estimated', $report->uniformity_method);
        $this->assertEqualsWithDelta(88.93, $report->std_dev_g, 0.05);
        $this->assertEqualsWithDelta(5.93, $report->cv_percent, 0.02);
        $this->assertEqualsWithDelta(90.8, $report->uniformity_percent, 0.2);
    }

    public function test_summary_payload_uses_counted_birds_within_band(): void
    {
        $this->api()->postJson($this->url(), [
            'flock_id' => $this->flock->id,
            'report_date' => now()->toDateString(),
            'average_weight' => 1.5,
            'min_weight' => 1.3,
            'max_weight' => 1.7,
            'sample_size' => 50,
            'birds_within_band' => 41,
        ])->assertStatus(201)
            ->assertJsonPath('data.uniformity_method', 'counted')
            ->assertJsonPath('data.uniformity_percent', 82);

        $this->api()->postJson($this->url(), [
            'flock_id' => $this->flock->id,
            'report_date' => now()->subDay()->toDateString(),
            'average_weight' => 1.5,
            'min_weight' => 1.3,
            'max_weight' => 1.7,
            'sample_size' => 50,
            'birds_within_band' => 60,
        ])->assertStatus(422);
    }

    public function test_sample_session_marks_uniformity_as_measured(): void
    {
        $this->api()->postJson($this->url(), [
            'flock_id' => $this->flock->id,
            'report_date' => now()->toDateString(),
            'target_weight_g' => 1000,
            'samples' => [['weight_g' => 980], ['weight_g' => 1020], ['weight_g' => 1000]],
        ])->assertStatus(201)
            ->assertJsonPath('data.uniformity_method', 'measured')
            ->assertJsonPath('data.uniformity_percent', 100);
    }

    public function test_last_target_returns_most_recent_target_for_sex(): void
    {
        foreach ([[10, 'mixed', 500], [3, 'male', 1400], [3, 'female', 1200]] as [$daysAgo, $sex, $target]) {
            $this->api()->postJson($this->url(), [
                'flock_id' => $this->flock->id,
                'report_date' => now()->subDays($daysAgo)->toDateString(),
                'sex' => $sex,
                'target_weight_g' => $target,
                'samples' => [['weight_g' => $target], ['weight_g' => $target + 20]],
            ])->assertStatus(201);
        }

        $this->api()->getJson($this->url('/last-target').'?flock_id='.$this->flock->id.'&sex=female')
            ->assertOk()
            ->assertJsonPath('data.target_weight_g', 1200);

        $this->api()->getJson($this->url('/last-target').'?flock_id='.$this->flock->id)
            ->assertOk()
            ->assertJsonPath('data.age_days', 18);
    }

    public function test_extract_from_image_returns_draft_grams_without_saving(): void
    {
        $this->enableAi();
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('visionChatMany')->once()->andReturn(json_encode([
            'unit' => 'kg',
            'samples' => [['weight' => 1.85], ['weight' => 1.9, 'sex' => 'male'], ['weight' => 1.78], ['weight' => 0.2]],
            'target_weight' => 1.95,
            'date' => '2026-10-10',
            'confidence' => 0.9,
            'warnings' => [],
        ]));
        $llm->shouldReceive('getLastError')->andReturn(null);
        $this->app->instance(LlmService::class, $llm);

        $file = UploadedFile::fake()->createWithContent('sheet.png', base64_decode(self::PNG_1X1));

        $response = $this->api()->post($this->url('/extract'), ['file' => $file, 'flock_id' => $this->flock->id], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.data_source', 'ai_image')
            ->assertJsonPath('data.target_weight_g', 1950)
            ->assertJsonPath('data.samples.0.weight_g', 1850)
            ->assertJsonPath('data.samples.1.sex', 'male')
            ->assertJsonCount(4, 'data.samples');

        $this->assertFalse($response->json('data.samples.0.is_outlier'));
        $this->assertTrue($response->json('data.samples.3.is_outlier'));
        Storage::disk('public')->assertExists($response->json('data.source_file_path'));
        $this->assertDatabaseCount('poultry_flock_weight_reports', 0);
    }

    public function test_extract_from_csv_falls_back_to_numbers_when_llm_unavailable(): void
    {
        $this->enableAi();
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chat')->once()->andReturn(null);
        $llm->shouldReceive('getLastError')->andReturn('offline');
        $this->app->instance(LlmService::class, $llm);

        $file = UploadedFile::fake()->createWithContent('weights.csv', "1850\n1920\n1785\n1810\n");

        $this->api()->post($this->url('/extract'), ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.data_source', 'ai_file')
            ->assertJsonPath('data.unit', 'g')
            ->assertJsonCount(4, 'data.samples')
            ->assertJsonPath('data.samples.1.weight_g', 1920);
    }

    public function test_daily_record_does_not_overwrite_detailed_session(): void
    {
        $date = now()->toDateString();

        $this->api()->postJson($this->url(), [
            'flock_id' => $this->flock->id,
            'report_date' => $date,
            'target_weight_g' => 1000,
            'samples' => [['weight_g' => 980], ['weight_g' => 1020], ['weight_g' => 1000]],
        ])->assertStatus(201);

        $this->api()->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
            'flock_id' => $this->flock->id,
            'date' => $date,
            'avg_weight_grams' => 1500,
            'min_weight_grams' => 1400,
            'max_weight_grams' => 1600,
            'sample_size' => 10,
        ])->assertStatus(201);

        $reports = PoultryFlockWeightReport::where('flock_id', $this->flock->id)->get();
        $this->assertCount(1, $reports);
        $this->assertSame('manual', $reports->first()->data_source);
        $this->assertEqualsWithDelta(1000.0, $reports->first()->average_weight_g, 0.01);
        $this->assertSame(3, $reports->first()->samples()->count());
    }

    public function test_daily_record_creates_summary_only_report_when_no_session_exists(): void
    {
        $this->api()->postJson("/api/farms/{$this->farm->id}/flock-daily-records", [
            'flock_id' => $this->flock->id,
            'date' => now()->toDateString(),
            'avg_weight_grams' => 1500,
            'min_weight_grams' => 1400,
            'max_weight_grams' => 1600,
            'sample_size' => 10,
        ])->assertStatus(201);

        $report = PoultryFlockWeightReport::where('flock_id', $this->flock->id)->firstOrFail();
        $this->assertSame('summary_only', $report->data_source);
        $this->assertEqualsWithDelta(1500.0, $report->average_weight_g, 0.01);
        $this->assertSame('estimated', $report->uniformity_method);
        $this->assertEqualsWithDelta(97.9, $report->uniformity_percent, 0.2);
    }
}
