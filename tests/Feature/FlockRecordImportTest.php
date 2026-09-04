<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockRecordImport;
use App\Models\FlockRecordImportItem;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use App\Services\FlockRecordImport\FlockRecordImportTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FlockRecordImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private string $token;
    private Flock $flock;
    private PoultryFeedType $feedType;
    private Role $ownerRole;

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

        $permissions = [
            'manage flocks',
            'view flocks',
            'update flocks',
            'create flocks',
            'create flock mortality reports',
            'create flock egg reports',
            'create feed usages',
            'create sales',
        ];

        $this->ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::findOrCreate($name, 'api', $this->farm->id);
            $this->ownerRole->givePermissionTo($permission);
        }

        $this->farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
        $this->user->assignRole($this->ownerRole);

        $poultryType = PoultryType::factory()->create(['name' => 'Layer']);
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
            'quantity' => 200,
            'arrival_date' => now()->subDays(20)->toDateString(),
            'arrival_age_days' => 1,
            'status' => 'active',
        ]);

        $this->feedType = PoultryFeedType::create([
            'farm_id' => $this->farm->id,
            'type' => 'user',
            'poultry_type_id' => $poultryType->id,
            'name' => 'Layer Mash',
            'description' => 'Test feed',
        ]);

        PoultryFeedInventory::create([
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->feedType->id,
            'quantity' => 1000,
            'unit_cost' => 2.5,
            'status' => 'available',
            'batch_number' => 'BATCH-IMPORT',
        ]);
    }

    private function baseUrl(): string
    {
        return "/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/record-imports";
    }

    public function test_template_download(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->get($this->baseUrl().'/template');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type')
        );
    }

    public function test_multi_sheet_xlsx_creates_draft_items(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fri_').'.xlsx';
        app(FlockRecordImportTemplateService::class)->writeToPath($tmp);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->post($this->baseUrl(), [
                'method' => 'file',
                'file' => new UploadedFile($tmp, 'template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ]);

        @unlink($tmp);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $draftId = $response->json('data.draft.id');
        $this->assertNotNull($draftId);
        $this->assertGreaterThan(0, FlockRecordImportItem::where('flock_record_import_id', $draftId)->count());
        $types = FlockRecordImportItem::where('flock_record_import_id', $draftId)->pluck('record_type')->unique()->all();
        $this->assertContains('daily', $types);
        $this->assertContains('mortality', $types);
    }

    public function test_single_sheet_csv_with_record_type_column(): void
    {
        $csv = "record_type,date,eggs_collected,average_egg_weight,notes\n"
            ."eggs,2026-09-01,100,55,csv row\n"
            ."expenditure,2026-09-01,labour,5000,,helpers\n";

        // expenditure needs category/amount — fix CSV columns
        $csv = "record_type,date,eggs_collected,average_egg_weight,category,amount,notes\n"
            ."eggs,2026-09-01,100,55,,,csv eggs\n"
            ."expenditure,2026-09-01,,,labour,5000,helpers\n";

        $tmp = tempnam(sys_get_temp_dir(), 'fri_').'.csv';
        file_put_contents($tmp, $csv);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->post($this->baseUrl(), [
                'method' => 'file',
                'file' => new UploadedFile($tmp, 'rows.csv', 'text/csv', null, true),
            ]);

        @unlink($tmp);

        $response->assertStatus(201);
        $items = $response->json('data.draft.items');
        $this->assertCount(2, $items);
        $types = collect($items)->pluck('record_type')->all();
        $this->assertEqualsCanonicalizing(['eggs', 'expenditure'], $types);
    }

    public function test_product_sales_alias_maps_to_product_sale(): void
    {
        $csv = "record_type,date,type,quantity,unit_price\n"
            ."product_sales,2026-07-10,egg,30,150\n"
            ."product_sales,2026-07-11,egg,30,150\n";

        $tmp = tempnam(sys_get_temp_dir(), 'fri_').'.csv';
        file_put_contents($tmp, $csv);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->post($this->baseUrl(), [
                'method' => 'file',
                'file' => new UploadedFile($tmp, 'egg-sales.csv', 'text/csv', null, true),
            ]);

        @unlink($tmp);

        $response->assertStatus(201);
        $items = $response->json('data.draft.items');
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertSame('product_sale', $item['record_type']);
            $this->assertNotSame('invalid', $item['status']);
        }
    }

    public function test_confirm_creates_records(): void
    {
        $draft = FlockRecordImport::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'created_by' => $this->user->id,
            'source_method' => 'file',
            'source_type' => 'csv',
            'status' => 'draft',
        ]);

        $draft->items()->create([
            'record_type' => 'eggs',
            'row_index' => 0,
            'payload' => ['date' => '2026-09-01', 'eggs_collected' => 120],
            'status' => 'valid',
        ]);
        $draft->items()->create([
            'record_type' => 'expenditure',
            'row_index' => 1,
            'payload' => ['date' => '2026-09-01', 'category' => 'labour', 'amount' => 8000],
            'status' => 'valid',
        ]);
        $draft->items()->create([
            'record_type' => 'feed_usage',
            'row_index' => 2,
            'payload' => [
                'date' => '2026-09-01',
                'quantity' => 10,
                'poultry_feed_type_id' => $this->feedType->id,
            ],
            'status' => 'valid',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson($this->baseUrl()."/{$draft->id}/confirm");

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.succeeded', 3);

        $this->assertDatabaseHas('poultry_flock_egg_reports', [
            'flock_id' => $this->flock->id,
            'eggs_collected' => 120,
        ]);
        $this->assertDatabaseHas('flock_expenditures', [
            'flock_id' => $this->flock->id,
            'category' => 'labour',
        ]);
        $this->assertDatabaseHas('poultry_feed_usages', [
            'flock_id' => $this->flock->id,
            'quantity' => 10,
        ]);
    }

    public function test_overlap_marks_typed_row_invalid(): void
    {
        $csv = "record_type,date,mortality_count,average_weight,eggs_collected,feed_consumption_kg\n"
            ."daily,2026-09-05,3,,0,5\n"
            ."mortality,2026-09-05,2,1.1,,\n";

        $tmp = tempnam(sys_get_temp_dir(), 'fri_').'.csv';
        file_put_contents($tmp, $csv);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->post($this->baseUrl(), [
                'method' => 'file',
                'file' => new UploadedFile($tmp, 'overlap.csv', 'text/csv', null, true),
            ]);

        @unlink($tmp);

        $response->assertStatus(201);
        $items = collect($response->json('data.draft.items'));
        $mortality = $items->firstWhere('record_type', 'mortality');
        $this->assertSame('invalid', $mortality['status']);
        $this->assertNotEmpty($mortality['validation_errors']);
    }

    public function test_ai_method_denied_without_ai_plan(): void
    {
        $plan = \App\Models\SubscriptionPlan::create([
            'slug' => 'basic-no-ai',
            'name' => 'Basic',
            'price_kobo' => 0,
            'ai_enabled' => false,
            'max_users' => 5,
            'max_active_flocks' => 10,
            'sort_order' => 1,
        ]);

        \App\Models\FarmSubscription::create([
            'farm_id' => $this->farm->id,
            'subscription_plan_id' => $plan->id,
            'status' => \App\Models\FarmSubscription::STATUS_ACTIVE,
            'trial_ends_at' => null,
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'fri_').'.csv';
        file_put_contents($tmp, "record_type,date,eggs_collected\neggs,2026-09-01,10\n");

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->post($this->baseUrl(), [
                'method' => 'ai',
                'file' => new UploadedFile($tmp, 'ai.csv', 'text/csv', null, true),
            ]);

        @unlink($tmp);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ai_not_included');
    }

    public function test_confirm_denied_without_permission_for_type(): void
    {
        // Strip egg permission
        $eggPerm = Permission::where('name', 'create flock egg reports')->first();
        if ($eggPerm) {
            $this->ownerRole->revokePermissionTo($eggPerm);
        }

        $draft = FlockRecordImport::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'created_by' => $this->user->id,
            'source_method' => 'file',
            'source_type' => 'csv',
            'status' => 'draft',
        ]);
        $draft->items()->create([
            'record_type' => 'eggs',
            'row_index' => 0,
            'payload' => ['date' => '2026-09-01', 'eggs_collected' => 10],
            'status' => 'valid',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson($this->baseUrl()."/{$draft->id}/confirm");

        $response->assertStatus(403);
    }
}
