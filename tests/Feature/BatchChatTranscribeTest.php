<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockStage;
use App\Models\Permission;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithSubscriptions;
use Tests\TestCase;

class BatchChatTranscribeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithSubscriptions;

    private User $user;

    private Farm $farm;

    private Flock $flock;

    private string $token;

    private string $base;

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

        $this->putFarmOnPlan($this->farm, SubscriptionPlan::PREMIUM);

        $role = Role::create([
            'name' => 'owner',
            'guard_name' => 'api',
            'farm_id' => $this->farm->id,
        ]);
        $permission = Permission::findOrCreate('view flocks', 'api');
        $role->givePermissionTo($permission);

        $this->farm->users()->attach($this->user->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->farm->id);
        $this->user->assignRole($role);

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

        $this->base = "/api/farms/{$this->farm->id}/flocks/{$this->flock->id}/batch-chat";

        config([
            'llm.provider' => 'openai',
            'llm.openai.api_key' => 'test-key',
            'llm.openai.base_url' => 'https://api.openai.com',
            'llm.openai.whisper_model' => 'whisper-1',
        ]);
    }

    public function test_transcribe_requires_ai_entitlement(): void
    {
        $this->putFarmOnPlan($this->farm, SubscriptionPlan::BASIC);

        $file = UploadedFile::fake()->create('voice.webm', 20, 'audio/webm');

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->post($this->base.'/transcribe', ['audio' => $file])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ai_not_included');
    }

    public function test_transcribe_requires_audio_file(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson($this->base.'/transcribe', [])
            ->assertStatus(422);
    }

    public function test_transcribe_returns_whisper_text(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Add 12 dead birds today',
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('voice.webm', 40, 'audio/webm');

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->post($this->base.'/transcribe', ['audio' => $file])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.text', 'Add 12 dead birds today');
    }

    public function test_transcribe_fails_when_whisper_returns_empty(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => '   ',
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('voice.webm', 40, 'audio/webm');

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->post($this->base.'/transcribe', ['audio' => $file])
            ->assertStatus(422);
    }
}
