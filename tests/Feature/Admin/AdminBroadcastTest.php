<?php

namespace Tests\Feature\Admin;

use App\Jobs\SendNotificationEmail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Notifications\NotificationChannel;
use Illuminate\Support\Facades\Queue;

class AdminBroadcastTest extends AdminTestCase
{
    public function test_super_admin_can_broadcast_platform_notification(): void
    {
        Queue::fake();

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/notifications/broadcast', [
                'title' => 'Flock updates',
                'body' => 'Egg sales now accept crates and Batch AI can query full months.',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recipients', 2);

        $this->assertDatabaseHas('notifications_center', [
            'user_id' => $this->regularUser->id,
            'type' => 'platform_broadcast',
            'title' => 'Flock updates',
            'status' => 'delivered',
        ]);
        $this->assertDatabaseHas('notifications_center', [
            'user_id' => $this->admin->id,
            'type' => 'platform_broadcast',
            'title' => 'Flock updates',
        ]);
        $this->assertSame(2, Notification::where('type', 'platform_broadcast')->count());

        $this->assertSame(
            2,
            NotificationDelivery::query()
                ->where('channel', NotificationChannel::EMAIL)
                ->where('status', 'queued')
                ->count()
        );

        Queue::assertPushedOn(config('notifications.queue'), SendNotificationEmail::class);
        Queue::assertPushed(SendNotificationEmail::class, 2);
    }

    public function test_broadcast_can_target_specific_farm_members(): void
    {
        Queue::fake();

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/notifications/broadcast', [
                'title' => 'Farm only',
                'body' => 'Hello farm members',
                'farm_ids' => [$this->farm->id],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.recipients', 1);

        $this->assertDatabaseHas('notifications_center', [
            'user_id' => $this->regularUser->id,
            'type' => 'platform_broadcast',
            'title' => 'Farm only',
        ]);
        $this->assertDatabaseMissing('notifications_center', [
            'user_id' => $this->admin->id,
            'type' => 'platform_broadcast',
            'title' => 'Farm only',
        ]);

        Queue::assertPushed(SendNotificationEmail::class, 1);
    }
}
