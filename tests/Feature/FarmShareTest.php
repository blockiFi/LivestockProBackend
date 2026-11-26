<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\FeedComponent;
use App\Models\FeedComposition;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Models\PoultryFeedProduct;
use App\Models\PoultryFeedType;
use App\Models\PoultryMedication;
use App\Models\PoultryType;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use App\Models\User;
use App\Services\FarmPermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FarmShareTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Farm $source;
    private Farm $ownedTarget;
    private Farm $workerFarm;
    private Farm $strangerFarm;
    private PoultryType $poultryType;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::factory()->create();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('t')->plainTextToken;
        $other = User::factory()->create();

        $make = fn (string $name, User $creator) => Farm::factory()->create([
            'name' => $name, 'created_by' => $creator->id, 'country_id' => $country->id, 'status' => true,
        ]);
        $this->source = $make('Source Farm', $this->user);
        $this->ownedTarget = $make('Second Farm', $this->user);
        $this->workerFarm = $make('Worker Farm', $other);
        $this->strangerFarm = $make('Stranger Farm', $other);

        $sync = app(FarmPermissionSyncService::class);
        foreach ([$this->source, $this->ownedTarget, $this->workerFarm, $this->strangerFarm] as $farm) {
            $sync->syncFarm($farm);
        }

        $this->joinAs($this->source, 'owner');
        $this->joinAs($this->ownedTarget, 'owner');
        $this->joinAs($this->workerFarm, 'worker');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->poultryType = PoultryType::factory()->create(['name' => 'Layer']);
    }

    private function joinAs(Farm $farm, string $role): void
    {
        $farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        $this->user->unsetRelation('roles');
        $this->user->assignRole(Role::where('name', $role)->where('farm_id', $farm->id)->first());
    }

    private function api()
    {
        return $this->as($this->token);
    }

    /** Switch caller; the auth guard otherwise keeps the first user across requests. */
    private function as(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_targets_only_list_farms_where_the_user_can_create(): void
    {
        $res = $this->api()->getJson("/api/farms/{$this->source->id}/share-targets?kind=schedule");

        $res->assertOk();
        $this->assertSame([$this->ownedTarget->id], collect($res->json('data'))->pluck('id')->all());
    }

    public function test_worker_cannot_share(): void
    {
        $this->api()->getJson("/api/farms/{$this->workerFarm->id}/share-targets?kind=feed_product")
            ->assertForbidden();
    }

    public function test_share_medication_schedule_copies_items_and_farm_medication(): void
    {
        $medication = PoultryMedication::create([
            'farm_id' => $this->source->id, 'type' => 'user', 'name' => 'Amprolium Plus',
        ]);
        $schedule = Schedule::create([
            'schedule_type' => 'medication', 'poultry_type_id' => $this->poultryType->id, 'type' => 'user',
            'farm_id' => $this->source->id, 'name' => 'Layer meds', 'description' => 'Weekly',
        ]);
        ScheduleItem::create([
            'schedule_id' => $schedule->id, 'age_days' => 7, 'poultry_medication_id' => $medication->id,
            'name' => 'Amprolium', 'dose' => 1, 'is_recurring' => true, 'interval_days' => 14,
        ]);
        // Same name already on target: the copy gets the source farm's name added.
        Schedule::create([
            'schedule_type' => 'medication', 'poultry_type_id' => $this->poultryType->id, 'type' => 'user',
            'farm_id' => $this->ownedTarget->id, 'name' => 'Layer meds',
        ]);

        $res = $this->api()->postJson("/api/farms/{$this->source->id}/share", [
            'kind' => 'schedule', 'id' => $schedule->id, 'target_farm_ids' => [$this->ownedTarget->id],
        ]);

        $res->assertCreated();
        $copy = Schedule::with('items')->find($res->json('data.0.id'));
        $this->assertSame($this->ownedTarget->id, (int) $copy->farm_id);
        $this->assertSame('Layer meds (from Source Farm)', $copy->name);
        $this->assertCount(1, $copy->items);
        $this->assertTrue((bool) $copy->items[0]->is_recurring);
        $this->assertSame(14, $copy->items[0]->interval_days);
        $copiedMed = PoultryMedication::find($copy->items[0]->poultry_medication_id);
        $this->assertSame($this->ownedTarget->id, (int) $copiedMed->farm_id);
        $this->assertSame('Amprolium Plus', $copiedMed->name);
    }

    public function test_cannot_share_to_farm_without_create_permission_or_membership(): void
    {
        $schedule = Schedule::create([
            'schedule_type' => 'vaccination', 'poultry_type_id' => $this->poultryType->id, 'type' => 'user',
            'farm_id' => $this->source->id, 'name' => 'Vax',
        ]);

        foreach ([$this->workerFarm, $this->strangerFarm] as $farm) {
            $this->api()->postJson("/api/farms/{$this->source->id}/share", [
                'kind' => 'schedule', 'id' => $schedule->id, 'target_farm_ids' => [$farm->id],
            ])->assertStatus(422);
        }
        $this->assertSame(1, Schedule::count());
    }

    public function test_cannot_share_another_farms_item(): void
    {
        $schedule = Schedule::create([
            'schedule_type' => 'vaccination', 'poultry_type_id' => $this->poultryType->id, 'type' => 'user',
            'farm_id' => $this->strangerFarm->id, 'name' => 'Not mine',
        ]);

        $this->api()->postJson("/api/farms/{$this->source->id}/share", [
            'kind' => 'schedule', 'id' => $schedule->id, 'target_farm_ids' => [$this->ownedTarget->id],
        ])->assertNotFound();
    }

    public function test_share_feeding_schedule(): void
    {
        $feedType = PoultryFeedType::create([
            'farm_id' => null, 'type' => 'default', 'poultry_type_id' => $this->poultryType->id, 'name' => 'Layer mash',
        ]);
        $schedule = FeedingSchedule::create([
            'title' => 'Layer feeding', 'type' => 'user', 'farm_id' => $this->source->id,
            'poultry_type_id' => $this->poultryType->id,
        ]);
        FeedingScheduleItem::create([
            'feeding_schedule_id' => $schedule->id, 'feed_type_id' => $feedType->id,
            'feeding_times' => [['time' => '08:00', 'percentage' => 100]], 'quantity' => 120,
            'start_day' => 1, 'end_day' => 140,
        ]);

        $res = $this->api()->postJson("/api/farms/{$this->source->id}/share", [
            'kind' => 'feeding_schedule', 'id' => $schedule->id, 'target_farm_ids' => [$this->ownedTarget->id],
        ])->assertCreated();

        $copy = FeedingSchedule::with('items')->find($res->json('data.0.id'));
        $this->assertSame($this->ownedTarget->id, (int) $copy->farm_id);
        $this->assertSame('Layer feeding', $copy->title);
        $this->assertSame(140, $copy->items[0]->end_day);
        $this->assertSame($feedType->id, (int) $copy->items[0]->feed_type_id);
    }

    public function test_share_feed_formulation_copies_compositions_and_components(): void
    {
        $maize = FeedComponent::create(['farm_id' => $this->source->id, 'name' => 'Maize', 'crude_protein' => 9]);
        $soya = FeedComponent::create(['farm_id' => $this->source->id, 'name' => 'Soya meal', 'crude_protein' => 44]);
        $existingSoya = FeedComponent::create(['farm_id' => $this->ownedTarget->id, 'name' => 'Soya meal', 'crude_protein' => 45]);
        $product = PoultryFeedProduct::create([
            'name' => 'Layer 16%', 'farm_id' => $this->source->id, 'crude_protein' => 16, 'status' => 'active',
        ]);
        FeedComposition::create(['poultry_feed_product_id' => $product->id, 'feed_component_id' => $maize->id, 'percentage' => 60]);
        FeedComposition::create(['poultry_feed_product_id' => $product->id, 'feed_component_id' => $soya->id, 'percentage' => 40]);

        $res = $this->api()->postJson("/api/farms/{$this->source->id}/share", [
            'kind' => 'feed_product', 'id' => $product->id, 'target_farm_ids' => [$this->ownedTarget->id],
        ])->assertCreated();

        $copy = PoultryFeedProduct::with('compositions.component')->find($res->json('data.0.id'));
        $this->assertSame($this->ownedTarget->id, (int) $copy->farm_id);
        $this->assertCount(2, $copy->compositions);
        $byName = $copy->compositions->keyBy(fn ($c) => $c->component->name);
        $this->assertSame($existingSoya->id, $byName['Soya meal']->feed_component_id);
        $this->assertSame($this->ownedTarget->id, (int) $byName['Maize']->component->farm_id);
        $this->assertEquals(60, $byName['Maize']->percentage);
    }
    private function strangerOwner(): array
    {
        $owner = User::factory()->create();
        $this->strangerFarm->users()->attach($owner->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->strangerFarm->id);
        $owner->assignRole(Role::where('name', 'owner')->where('farm_id', $this->strangerFarm->id)->first());
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return [$owner, $owner->createToken('o')->plainTextToken];
    }

    private function feedProduct(): PoultryFeedProduct
    {
        $maize = FeedComponent::create(['farm_id' => $this->source->id, 'name' => 'Maize', 'crude_protein' => 9]);
        $product = PoultryFeedProduct::create(['name' => 'Grower 18%', 'farm_id' => $this->source->id, 'status' => 'active']);
        FeedComposition::create(['poultry_feed_product_id' => $product->id, 'feed_component_id' => $maize->id, 'percentage' => 100]);

        return $product;
    }

    public function test_send_by_code_then_accept_creates_copy_on_other_farm(): void
    {
        [, $strangerToken] = $this->strangerOwner();
        $code = $this->as($strangerToken)
            ->getJson("/api/farms/{$this->strangerFarm->id}/share-code")->assertOk()->json('data.code');
        $this->assertSame(8, strlen($code));

        $this->api()->getJson("/api/farms/{$this->source->id}/share-lookup?code=".strtolower($code))
            ->assertOk()->assertJsonPath('data.name', 'Stranger Farm');

        $product = $this->feedProduct();
        $shareId = $this->api()->postJson("/api/farms/{$this->source->id}/share/send", [
            'kind' => 'feed_product', 'id' => $product->id, 'farm_code' => $code, 'message' => 'Try this one',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        // Sending the same item again while it is pending is refused.
        $this->api()->postJson("/api/farms/{$this->source->id}/share/send", [
            'kind' => 'feed_product', 'id' => $product->id, 'farm_code' => $code,
        ])->assertStatus(422);

        $this->assertSame(0, PoultryFeedProduct::where('farm_id', $this->strangerFarm->id)->count());

        $incoming = $this->as($strangerToken)
            ->getJson("/api/farms/{$this->strangerFarm->id}/shares/incoming")->assertOk();
        $incoming->assertJsonPath('data.0.can_respond', true)->assertJsonPath('data.0.source_farm.name', 'Source Farm');

        $this->as($strangerToken)
            ->postJson("/api/farms/{$this->strangerFarm->id}/shares/{$shareId}/accept")
            ->assertOk()->assertJsonPath('data.status', 'accepted');

        $copy = PoultryFeedProduct::with('compositions.component')->where('farm_id', $this->strangerFarm->id)->first();
        $this->assertSame('Grower 18%', $copy->name);
        $this->assertSame($this->strangerFarm->id, (int) $copy->compositions[0]->component->farm_id);

        // Cannot be accepted twice.
        $this->as($strangerToken)
            ->postJson("/api/farms/{$this->strangerFarm->id}/shares/{$shareId}/accept")->assertStatus(422);

        $this->api()->getJson("/api/farms/{$this->source->id}/shares/outgoing")
            ->assertOk()->assertJsonPath('data.0.status', 'accepted');
    }

    public function test_decline_and_only_target_farm_can_answer(): void
    {
        [, $strangerToken] = $this->strangerOwner();
        $code = app(\App\Services\FarmShareService::class)->shareCodeFor($this->strangerFarm);
        $schedule = Schedule::create([
            'schedule_type' => 'vaccination', 'poultry_type_id' => $this->poultryType->id, 'type' => 'user',
            'farm_id' => $this->source->id, 'name' => 'Vax plan',
        ]);
        $shareId = $this->api()->postJson("/api/farms/{$this->source->id}/share/send", [
            'kind' => 'schedule', 'id' => $schedule->id, 'farm_code' => $code,
        ])->assertCreated()->json('data.id');

        // The sender cannot accept on the target farm's behalf (not a member there).
        $this->api()->postJson("/api/farms/{$this->strangerFarm->id}/shares/{$shareId}/accept")->assertForbidden();
        // Nor through their own farm.
        $this->api()->postJson("/api/farms/{$this->source->id}/shares/{$shareId}/accept")->assertNotFound();

        $this->as($strangerToken)
            ->postJson("/api/farms/{$this->strangerFarm->id}/shares/{$shareId}/decline")
            ->assertOk()->assertJsonPath('data.status', 'declined');
        $this->assertSame(0, Schedule::where('farm_id', $this->strangerFarm->id)->count());
    }

    public function test_worker_on_target_farm_cannot_accept(): void
    {
        $code = app(\App\Services\FarmShareService::class)->shareCodeFor($this->workerFarm);
        $shareId = $this->api()->postJson("/api/farms/{$this->source->id}/share/send", [
            'kind' => 'feed_product', 'id' => $this->feedProduct()->id, 'farm_code' => $code,
        ])->assertCreated()->json('data.id');

        $this->api()->getJson("/api/farms/{$this->workerFarm->id}/shares/incoming")->assertForbidden();
        $this->api()->postJson("/api/farms/{$this->workerFarm->id}/shares/{$shareId}/accept")->assertForbidden();
    }

    public function test_sender_can_cancel_pending_share(): void
    {
        $code = app(\App\Services\FarmShareService::class)->shareCodeFor($this->strangerFarm);
        $shareId = $this->api()->postJson("/api/farms/{$this->source->id}/share/send", [
            'kind' => 'feed_product', 'id' => $this->feedProduct()->id, 'farm_code' => $code,
        ])->json('data.id');

        $this->api()->postJson("/api/farms/{$this->source->id}/shares/{$shareId}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_unknown_code_is_rejected(): void
    {
        $this->api()->postJson("/api/farms/{$this->source->id}/share/send", [
            'kind' => 'feed_product', 'id' => $this->feedProduct()->id, 'farm_code' => 'NOPE1234',
        ])->assertStatus(422);
    }
}
