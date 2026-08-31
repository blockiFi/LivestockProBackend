<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\FarmFeedTypeAgeRange;
use App\Models\Permission;
use App\Models\PoultryFeedType;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedAgeRangeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $viewer;
    private Farm $farm;
    private string $ownerToken;
    private string $viewerToken;
    private PoultryFeedType $starter;
    private PoultryFeedType $grower;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::factory()->create();
        $this->owner = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->ownerToken = $this->owner->createToken('owner')->plainTextToken;
        $this->viewerToken = $this->viewer->createToken('viewer')->plainTextToken;

        $this->farm = Farm::factory()->create([
            'created_by' => $this->owner->id,
            'country_id' => $country->id,
        ]);
        $this->farm->users()->attach([$this->owner->id, $this->viewer->id]);

        $viewFeedTypes = Permission::firstOrCreate(['name' => 'view feed types', 'guard_name' => 'api']);
        $updateFeedTypes = Permission::firstOrCreate(['name' => 'update feed types', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage farm settings', 'guard_name' => 'api']);

        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $ownerRole->givePermissionTo([$viewFeedTypes, $updateFeedTypes]);
        $this->owner->assignRole($ownerRole);

        $viewerRole = Role::create([
            'name' => 'viewer',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $viewerRole->givePermissionTo([$viewFeedTypes]);
        $this->viewer->assignRole($viewerRole);

        $poultryType = PoultryType::factory()->create(['name' => 'Broiler']);

        $this->starter = PoultryFeedType::create([
            'farm_id' => null,
            'type' => 'default',
            'poultry_type_id' => $poultryType->id,
            'name' => 'Starter Age Test',
            'description' => 'Starter',
            'start_age' => 1,
            'end_age' => 14,
        ]);

        $this->grower = PoultryFeedType::create([
            'farm_id' => null,
            'type' => 'default',
            'poultry_type_id' => $poultryType->id,
            'name' => 'Grower Age Test',
            'description' => 'Grower',
            'start_age' => 15,
            'end_age' => 35,
        ]);
    }

    public function test_get_returns_global_default_range_when_no_override(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->getJson("/api/farms/{$this->farm->id}/feed-age-ranges")
            ->assertStatus(200);

        $starter = collect($response->json('data'))->firstWhere('id', $this->starter->id);

        $this->assertNotNull($starter);
        $this->assertEquals(1, $starter['effective_start_age']);
        $this->assertEquals(14, $starter['effective_end_age']);
        $this->assertFalse($starter['has_farm_override']);
    }

    public function test_put_creates_override_and_omitting_row_deletes_it(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->putJson("/api/farms/{$this->farm->id}/feed-age-ranges", [
                'ranges' => [
                    [
                        'poultry_feed_type_id' => $this->starter->id,
                        'start_age' => 1,
                        'end_age' => 10,
                    ],
                    [
                        'poultry_feed_type_id' => $this->grower->id,
                        'start_age' => 11,
                        'end_age' => 40,
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('farm_feed_type_age_ranges', [
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->starter->id,
            'start_age' => 1,
            'end_age' => 10,
        ]);

        // Global default row is unchanged
        $this->assertDatabaseHas('poultry_feed_types', [
            'id' => $this->starter->id,
            'start_age' => 1,
            'end_age' => 14,
        ]);

        // Omit grower — its override is deleted
        $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->putJson("/api/farms/{$this->farm->id}/feed-age-ranges", [
                'ranges' => [
                    [
                        'poultry_feed_type_id' => $this->starter->id,
                        'start_age' => 1,
                        'end_age' => 12,
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->assertDatabaseMissing('farm_feed_type_age_ranges', [
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->grower->id,
        ]);

        $this->assertDatabaseHas('farm_feed_type_age_ranges', [
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->starter->id,
            'end_age' => 12,
        ]);
    }

    public function test_end_age_below_start_age_returns_422(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->putJson("/api/farms/{$this->farm->id}/feed-age-ranges", [
                'ranges' => [
                    [
                        'poultry_feed_type_id' => $this->starter->id,
                        'start_age' => 10,
                        'end_age' => 5,
                    ],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_user_without_update_permission_gets_403(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->viewerToken)
            ->putJson("/api/farms/{$this->farm->id}/feed-age-ranges", [
                'ranges' => [
                    [
                        'poultry_feed_type_id' => $this->starter->id,
                        'start_age' => 1,
                        'end_age' => 7,
                    ],
                ],
            ])
            ->assertStatus(403);

        $this->assertEquals(0, FarmFeedTypeAgeRange::where('farm_id', $this->farm->id)->count());
        $this->assertDatabaseHas('poultry_feed_types', [
            'id' => $this->starter->id,
            'start_age' => 1,
            'end_age' => 14,
        ]);
    }

    public function test_manage_farm_settings_can_update_feed_age_ranges(): void
    {
        $manager = User::factory()->create();
        $managerToken = $manager->createToken('manager')->plainTextToken;
        $this->farm->users()->attach($manager->id);

        $manageSettings = Permission::firstOrCreate([
            'name' => 'manage farm settings',
            'guard_name' => 'api',
        ]);
        $role = Role::create([
            'name' => 'settings-manager',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $role->givePermissionTo([$manageSettings]);
        $manager->assignRole($role);

        $this->withHeader('Authorization', 'Bearer ' . $managerToken)
            ->putJson("/api/farms/{$this->farm->id}/feed-age-ranges", [
                'ranges' => [
                    [
                        'poultry_feed_type_id' => $this->starter->id,
                        'start_age' => 1,
                        'end_age' => 9,
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('farm_feed_type_age_ranges', [
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->starter->id,
            'end_age' => 9,
        ]);
    }

    public function test_open_ended_end_age_is_accepted(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->putJson("/api/farms/{$this->farm->id}/feed-age-ranges", [
                'ranges' => [
                    [
                        'poultry_feed_type_id' => $this->starter->id,
                        'start_age' => 127,
                        'end_age' => null,
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('farm_feed_type_age_ranges', [
            'farm_id' => $this->farm->id,
            'poultry_feed_type_id' => $this->starter->id,
            'start_age' => 127,
            'end_age' => null,
        ]);
    }
}
