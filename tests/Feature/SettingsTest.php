<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\FarmSetting;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $worker;
    private Farm $farm;
    private Flock $flock;
    private string $ownerToken;
    private string $workerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::factory()->create();
        $this->owner = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);
        $this->worker = User::factory()->create();
        $this->ownerToken = $this->owner->createToken('owner-token')->plainTextToken;
        $this->workerToken = $this->worker->createToken('worker-token')->plainTextToken;

        $this->farm = Farm::factory()->create([
            'created_by' => $this->owner->id,
            'country_id' => $country->id,
        ]);

        $this->farm->users()->attach([$this->owner->id, $this->worker->id]);

        $permissions = collect([
            'view farm',
            'update farm',
            'manage farm settings',
            'view flocks',
        ])->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'api',
        ]));

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo($permissions);
        $this->owner->assignRole($ownerRole);

        $workerRole = Role::create([
            'name' => 'worker',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $workerRole->givePermissionTo($permissions->where('name', 'view farm')->concat($permissions->where('name', 'view flocks')));
        $this->worker->assignRole($workerRole);

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
            'status' => 'active',
        ]);
    }

    public function test_user_can_update_profile_details(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->postJson('/api/user/profile', [
                'name' => 'Updated Owner',
                'email' => 'updated-owner@example.com',
                'phone' => '08012345678',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Owner')
            ->assertJsonPath('data.email', 'updated-owner@example.com')
            ->assertJsonPath('data.phone', '08012345678');

        $this->assertDatabaseHas('users', [
            'id' => $this->owner->id,
            'name' => 'Updated Owner',
            'email' => 'updated-owner@example.com',
            'phone' => '08012345678',
        ]);
    }

    public function test_profile_update_requires_unique_email(): void
    {
        $otherUser = User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->postJson('/api/user/profile', [
                'email' => $otherUser->email,
            ]);

        $response->assertStatus(422);
    }

    public function test_user_can_upload_profile_photo(): void
    {
        Storage::fake('public');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->post('/api/user/profile', [
                'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
            ]);

        $response->assertStatus(200);

        $path = $response->json('data.profile_photo');
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_user_preferences_can_be_retrieved_and_updated(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->getJson('/api/user/preferences')
            ->assertStatus(200)
            ->assertJsonPath('data.theme', 'light');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->putJson('/api/user/preferences', [
                'theme' => 'light',
                'locale' => 'en-GB',
                'timezone' => 'Africa/Lagos',
                'date_format' => 'd/m/Y',
                'notify_schedules' => false,
                'notify_low_stock' => true,
                'notify_mortality' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.theme', 'light')
            ->assertJsonPath('data.notify_schedules', false)
            ->assertJsonPath('data.notify_mortality', false);

        $this->assertDatabaseHas('user_settings', [
            'user_id' => $this->owner->id,
            'theme' => 'light',
            'locale' => 'en-GB',
            'timezone' => 'Africa/Lagos',
            'date_format' => 'd/m/Y',
            'notify_schedules' => 0,
            'notify_low_stock' => 1,
            'notify_mortality' => 0,
        ]);
    }

    public function test_owner_can_update_farm_settings(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->putJson("/api/farms/{$this->farm->id}/settings", [
                'currency_code' => 'USD',
                'currency_symbol' => '$',
                'timezone' => 'America/New_York',
                'date_format' => 'm/d/Y',
                'invoice_tax_enabled' => false,
                'invoice_tax_rate' => 0,
                'invoice_payment_instructions' => 'Pay to account 123',
                'schedule_reminder_days' => 2,
                'low_stock_alerts_enabled' => false,
                'mortality_alert_percent' => 4.5,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.currency_code', 'USD')
            ->assertJsonPath('data.low_stock_alerts_enabled', false);

        $this->assertDatabaseHas('farm_settings', [
            'farm_id' => $this->farm->id,
            'currency_code' => 'USD',
            'currency_symbol' => '$',
            'timezone' => 'America/New_York',
            'date_format' => 'm/d/Y',
            'invoice_tax_enabled' => 0,
            'schedule_reminder_days' => 2,
            'low_stock_alerts_enabled' => 0,
        ]);
    }

    public function test_user_without_manage_farm_settings_cannot_update_farm_settings(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->workerToken)
            ->putJson("/api/farms/{$this->farm->id}/settings", [
                'currency_code' => 'EUR',
            ])
            ->assertStatus(403);
    }

    public function test_logout_other_devices_revokes_other_tokens_only(): void
    {
        $extraToken = $this->owner->createToken('other-device');
        $extraTokenId = $extraToken->accessToken->id;

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->postJson('/api/user/logout-other-devices');

        $response->assertStatus(200);

        $this->assertCount(1, $this->owner->fresh()->tokens);

        $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->getJson('/api/user')
            ->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $extraTokenId,
        ]);
    }

    public function test_notifications_respect_saved_farm_settings(): void
    {
        FarmSetting::create([
            'farm_id' => $this->farm->id,
            'schedule_reminder_days' => 0,
            'low_stock_alerts_enabled' => false,
            'mortality_alert_percent' => 1.5,
        ]);

        PoultryMortalityReport::create([
            'farm_id' => $this->farm->id,
            'flock_id' => $this->flock->id,
            'poultry_type_id' => $this->flock->poultry_type_id,
            'date' => now()->toDateString(),
            'mortality_count' => 3,
            'bird_count' => 100,
            'mortality_percentage' => 3.0,
            'recorded_by' => $this->owner->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->getJson("/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/notifications");

        $response->assertStatus(200)
            ->assertJsonPath('data.settings.schedule_reminder_days', 0)
            ->assertJsonPath('data.settings.low_stock_alerts_enabled', false)
            ->assertJsonPath('data.settings.mortality_alert_percent', 1.5);

        $this->assertEmpty($response->json('data.low_stock.medications'));
        $this->assertEmpty($response->json('data.low_stock.vaccines'));
        $this->assertEmpty($response->json('data.low_stock.feeds'));
        $this->assertCount(1, $response->json('data.mortality_alerts'));
    }
}
