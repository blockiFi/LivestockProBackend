<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSubscriptions;
use Tests\TestCase;

class AiEntitlementTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithSubscriptions;

    /**
     * @return array{0: string, 1: string, 2: array<string,mixed>}
     */
    public static function aiRouteProvider(): array
    {
        return [
            'formulate' => ['post', '/api/farms/{farm}/formulate-feed', ['feed_type_id' => 1]],
            'revise' => ['post', '/api/farms/{farm}/formulate-feed/revise', ['formula' => []]],
            'generate-component' => ['post', '/api/farms/{farm}/feed-components/generate-ai', ['name' => 'Maize']],
            'schedule-import' => ['post', '/api/farms/{farm}/ai/schedule-imports', []],
        ];
    }

    #[DataProvider('aiRouteProvider')]
    public function test_basic_plan_is_blocked_from_ai_routes(string $method, string $path, array $payload): void
    {
        [$farm, $token] = $this->farmOn(SubscriptionPlan::BASIC);
        $uri = str_replace('{farm}', (string) $farm->id, $path);

        $response = $this->withToken($token)->json($method, $uri, $payload);

        $response->assertStatus(403)->assertJsonPath('code', 'ai_not_included');
    }

    #[DataProvider('aiRouteProvider')]
    public function test_standard_plan_is_blocked_from_ai_routes(string $method, string $path, array $payload): void
    {
        [$farm, $token] = $this->farmOn(SubscriptionPlan::STANDARD);
        $uri = str_replace('{farm}', (string) $farm->id, $path);

        $response = $this->withToken($token)->json($method, $uri, $payload);

        $response->assertStatus(403)->assertJsonPath('code', 'ai_not_included');
    }

    public function test_premium_plan_passes_the_ai_gate(): void
    {
        [$farm, $token] = $this->farmOn(SubscriptionPlan::PREMIUM);
        $deps = $this->flockDependencies($farm);
        $flock = $this->createActiveFlock($farm, $deps);

        $response = $this->withToken($token)->getJson(
            "/api/farms/{$farm->id}/flocks/{$flock->id}/metrics/ai-insights"
        );

        $this->assertNotEquals(403, $response->status());
        $this->assertNotEquals('ai_not_included', $response->json('code'));
    }

    public function test_basic_plan_can_read_comparative_metrics_without_ai_refresh(): void
    {
        [$farm, $token] = $this->farmOn(SubscriptionPlan::BASIC);
        $deps = $this->flockDependencies($farm);
        $flock = $this->createActiveFlock($farm, $deps);

        $get = $this->withToken($token)->getJson(
            "/api/farms/{$farm->id}/flocks/{$flock->id}/metrics/comparative"
        );
        $this->assertNotEquals('ai_not_included', $get->json('code'));

        $refresh = $this->withToken($token)->postJson(
            "/api/farms/{$farm->id}/flocks/{$flock->id}/metrics/comparative"
        );
        $refresh->assertStatus(403)->assertJsonPath('code', 'ai_not_included');
    }

    /**
     * @return array{0: \App\Models\Farm, 1: string}
     */
    private function farmOn(string $slug): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $farm = $this->createSubscribedFarm($user, $token)['farm'];
        $this->putFarmOnPlan($farm, $slug);

        return [$farm, $token];
    }
}
