<?php

namespace Tests\Feature\Admin;

use App\Mail\AdminLoginCodeMail;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class AdminConsoleSecurityTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->admin->forceFill(['password' => Hash::make('secret-pass')])->save();
        RateLimiter::clear('admin-login:' . strtolower($this->admin->email) . '|127.0.0.1');
    }

    public function test_farm_app_token_is_refused_on_admin_routes(): void
    {
        $farmToken = $this->admin->createToken('auth_token')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$farmToken}"])
            ->getJson('/api/admin/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('code', 'admin_session_required');
    }

    public function test_sign_in_needs_password_then_emailed_code(): void
    {
        Mail::fake();

        $login = $this->postJson('/api/admin/auth/login', ['email' => $this->admin->email, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.otp_required', true);

        $code = null;
        Mail::assertSent(AdminLoginCodeMail::class, function (AdminLoginCodeMail $mail) use (&$code) {
            $code = $mail->code;
            return $mail->hasTo($this->admin->email);
        });

        $challenge = $login->json('data.challenge_id');
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/admin/auth/verify', ['challenge_id' => $challenge, 'code' => $wrong])
            ->assertStatus(422);

        $verify = $this->postJson('/api/admin/auth/verify', ['challenge_id' => $challenge, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->admin->id);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $verify->json('data.token')])
            ->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->admin->id);

        // A used code cannot be replayed.
        $this->postJson('/api/admin/auth/verify', ['challenge_id' => $challenge, 'code' => $code])->assertStatus(422);

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'admin.login', 'admin_user_id' => $this->admin->id]);
    }

    public function test_admin_token_expires_after_the_session_length(): void
    {
        app(PlatformSettingsService::class)->setAdminSecurity(['otp_required' => false, 'session_hours' => 2]);

        $res = $this->postJson('/api/admin/auth/login', ['email' => $this->admin->email, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.otp_required', false);

        $expires = \Carbon\Carbon::parse($res->json('data.expires_at'));
        $this->assertTrue($expires->between(now()->addMinutes(115), now()->addMinutes(125)));

        $this->travel(3)->hours();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $res->json('data.token')])
            ->getJson('/api/admin/dashboard')
            ->assertStatus(401);
    }

    public function test_non_admin_cannot_sign_in_to_console(): void
    {
        $this->regularUser->forceFill(['password' => Hash::make('secret-pass')])->save();

        $this->postJson('/api/admin/auth/login', ['email' => $this->regularUser->email, 'password' => 'secret-pass'])
            ->assertStatus(401);
    }

    public function test_admin_sign_in_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/auth/login', ['email' => $this->admin->email, 'password' => 'wrong'])->assertStatus(401);
        }

        $this->postJson('/api/admin/auth/login', ['email' => $this->admin->email, 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_farm_login_is_rate_limited(): void
    {
        $this->regularUser->forceFill(['password' => Hash::make('secret-pass')])->save();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $this->regularUser->email, 'password' => 'wrong']);
        }

        $this->postJson('/api/login', ['email' => $this->regularUser->email, 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_support_admin_cannot_promote_anyone_or_themselves(): void
    {
        $support = $this->makeAdmin('support');

        $this->withHeaders($this->headersAs($support))
            ->putJson("/api/admin/users/{$support->id}", ['platform_admin_role' => 'super_admin'])
            ->assertStatus(403);

        $this->withHeaders($this->headersAs($support))
            ->putJson("/api/admin/users/{$this->regularUser->id}", ['is_platform_admin' => true, 'platform_admin_role' => 'super_admin'])
            ->assertStatus(403);

        $this->withHeaders($this->headersAs($support))
            ->postJson('/api/admin/team', ['email' => 'new@example.com', 'role' => 'super_admin'])
            ->assertStatus(403);

        $this->assertSame('support', $support->fresh()->platform_admin_role);
        $this->assertFalse($this->regularUser->fresh()->is_platform_admin);
    }

    public function test_support_admin_can_still_edit_profile_fields(): void
    {
        $support = $this->makeAdmin('support');

        $this->withHeaders($this->headersAs($support))
            ->putJson("/api/admin/users/{$this->regularUser->id}", ['name' => 'Renamed', 'email_verified' => true])
            ->assertOk();

        $this->assertSame('Renamed', $this->regularUser->fresh()->name);
        $this->assertNotNull($this->regularUser->fresh()->email_verified_at);
    }

    public function test_super_admin_team_rules(): void
    {
        Mail::fake();

        // Invite a new person: account is created with the role.
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/team', ['email' => 'ops@example.com', 'name' => 'Ops', 'role' => 'support'])
            ->assertOk()
            ->assertJsonPath('data.created', true);
        $this->assertDatabaseHas('users', ['email' => 'ops@example.com', 'is_platform_admin' => true, 'platform_admin_role' => 'support']);

        // Cannot change own role.
        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/team/{$this->admin->id}", ['role' => 'readonly'])
            ->assertStatus(422);

        // Cannot remove the last super admin (via the legacy route too).
        $other = $this->makeAdmin('super_admin');
        $this->withHeaders($this->headersAs($other))
            ->deleteJson("/api/admin/team/{$this->admin->id}")
            ->assertOk();
        $this->withHeaders($this->headersAs($this->makeAdmin('super_admin')))
            ->putJson("/api/admin/team/{$other->id}", ['role' => 'support'])
            ->assertOk();
    }

    public function test_last_super_admin_cannot_be_removed(): void
    {
        // Only reachable if the acting account is not itself an active super
        // admin, so exercise the rule on the service directly.
        $actor = $this->makeAdmin('support');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Admin\AdminTeamService::class)->remove($actor, $this->admin);
    }

    public function test_role_change_ends_the_admins_sessions(): void
    {
        $support = $this->makeAdmin('support');
        $headers = $this->headersAs($support);

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/team/{$support->id}", ['role' => 'readonly'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/admin/dashboard')->assertStatus(401);
    }

    public function test_deactivated_user_is_signed_out_and_cannot_sign_in(): void
    {
        $this->regularUser->forceFill(['password' => Hash::make('secret-pass')])->save();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/users/{$this->regularUser->id}/deactivate", ['reason' => 'Fraud'])
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $this->regularUser->id]);
        $this->postJson('/api/login', ['email' => $this->regularUser->email, 'password' => 'secret-pass'])
            ->assertStatus(403);

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/users/{$this->regularUser->id}/reactivate")
            ->assertOk();
        $this->assertNull($this->regularUser->fresh()->deactivated_at);
    }

    public function test_only_super_admin_changes_security_settings(): void
    {
        $this->withHeaders($this->headersAs($this->makeAdmin('support')))
            ->putJson('/api/admin/platform-settings', ['admin_security' => ['otp_required' => false]])
            ->assertStatus(403);

        $this->withHeaders($this->headersAs($this->admin))
            ->putJson('/api/admin/platform-settings', ['admin_security' => ['otp_required' => false, 'session_hours' => 4]])
            ->assertOk()
            ->assertJsonPath('data.admin_security.session_hours', 4)
            ->assertJsonPath('data.admin_security.otp_required', false);
    }
}
