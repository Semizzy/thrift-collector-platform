<?php

namespace Tests\Feature;

use App\Domain\Tenancy\BusinessPermission;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Notifications\ApiPasswordResetNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_returns_a_bearer_token_but_only_hash_is_stored_and_sends_verification(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Amina Collector',
            'email' => 'amina@example.test',
            'password' => 'a-strong-test-password',
            'password_confirmation' => 'a-strong-test-password',
        ]);

        $response->assertCreated()->assertJsonPath('token_type', 'Bearer');
        $plainToken = $response->json('access_token');
        self::assertIsString($plainToken);
        self::assertSame(64, strlen($plainToken));
        self::assertDatabaseHas('personal_access_tokens', [
            'user_id' => $response->json('data.id'),
            'token_hash' => hash('sha256', $plainToken),
        ]);
        self::assertDatabaseMissing('personal_access_tokens', ['token_hash' => $plainToken]);
        Notification::assertSentTo(User::query()->findOrFail($response->json('data.id')), VerifyEmail::class);
    }

    public function test_registration_rejects_malformed_email_and_mismatched_password_confirmation(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Amina Collector',
            'email' => 'not-an-email',
            'password' => 'a-strong-test-password',
            'password_confirmation' => 'a-strong-test-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Amina Collector',
            'email' => 'amina@example.test',
            'password' => 'a-strong-test-password',
            'password_confirmation' => 'a-different-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_registration_rejects_missing_name_and_short_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'amina@example.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'password']);
    }

    public function test_signed_email_verification_activates_business_access(): void
    {
        $user = User::query()->create([
            'name' => 'Verify Me',
            'email' => 'verify@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(20), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->getJson($url)->assertOk()->assertJsonPath('message', 'Email address verified.');
        self::assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_password_recovery_is_non_enumerating_and_sends_reset_notification(): void
    {
        Notification::fake();
        $user = User::query()->create([
            'name' => 'Reset Me',
            'email' => 'reset@example.test',
            'password' => 'a-strong-test-password',
        ]);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'If an account matches that email, password reset instructions will be sent.');

        Notification::assertSentTo($user, ApiPasswordResetNotification::class);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'missing@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account matches that email, password reset instructions will be sent.');
    }

    public function test_password_reset_changes_password_and_revokes_existing_tokens(): void
    {
        $user = User::query()->create([
            'name' => 'Reset Token User',
            'email' => 'reset-token@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $accessToken = str_repeat('e', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $accessToken),
            'expires_at' => now()->addDay(),
        ]);
        $resetToken = Password::createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'another-strong-password',
            'password_confirmation' => 'another-strong-password',
        ])->assertOk();

        self::assertTrue(Hash::check('another-strong-password', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['user_id' => $user->id]);
    }

    public function test_three_failed_logins_lock_account_until_password_reset_and_admin_configured_timeout(): void
    {
        // This test verifies account lockout, not the independent per-IP HTTP throttle.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $user = User::query()->create([
            'name' => 'Lockout User',
            'email' => 'lockout@example.test',
            'password' => 'a-strong-test-password',
        ]);
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'login_lockout_hours'],
            ['value' => '4', 'updated_at' => now()]
        );

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'incorrect-password'])
                ->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'incorrect-password'])
            ->assertStatus(423);
        $user->refresh();
        self::assertSame(3, (int) $user->failed_login_attempts);
        self::assertTrue($user->login_locked_until->between(now()->addHours(3)->subMinute(), now()->addHours(4)->addMinute()));

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'a-strong-test-password'])
            ->assertStatus(423);

        $resetToken = Password::createToken($user);
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertOk();

        $user->refresh();
        self::assertSame(0, (int) $user->failed_login_attempts);
        self::assertNull($user->login_locked_until);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'a-new-strong-password'])
            ->assertOk()->assertJsonPath('token_type', 'Bearer');
    }

    public function test_authorized_platform_admin_can_change_audited_login_lockout_duration(): void
    {
        $admin = User::query()->create([
            'name' => 'Security Admin', 'email' => 'security-admin@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $admin->forceFill([
            'platform_role' => 'admin',
            'platform_permissions' => ['platform.security.manage'],
        ])->save();
        $plain = str_repeat('s', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $admin->id, 'name' => 'security-admin-test',
            'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDay(),
        ]);

        $this->withToken($plain)->getJson('/api/v1/admin/security-settings')
            ->assertOk()->assertJsonPath('data.failed_login_attempt_limit', 3)
            ->assertJsonPath('data.login_lockout_hours', 1)
            ->assertJsonPath('data.transaction_pin_failed_attempt_limit', 3);

        $this->withToken($plain)->putJson('/api/v1/admin/security-settings', [
            'login_lockout_hours' => 6,
            'reason' => 'Increase lockout time after credential abuse.',
        ])->assertOk()->assertJsonPath('data.login_lockout_hours', 6);

        $this->assertDatabaseHas('platform_settings', ['key' => 'login_lockout_hours', 'value' => '6']);
        $this->assertDatabaseHas('platform_audit_events', [
            'actor_user_id' => $admin->id,
            'action' => 'platform.security.login_lockout_updated',
        ]);
    }

    public function test_reset_password_page_renders(): void
    {
        $this->get('/reset-password?email=person%40example.test&token=sample-token')
            ->assertOk()
            ->assertSee('Reset your password');
    }

    public function test_business_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/businesses')->assertUnauthorized();
    }

    public function test_user_can_create_business_and_cannot_access_a_business_they_do_not_belong_to(): void
    {
        $this->seed();
        $owner = User::query()->create([
            'name' => 'Business Owner',
            'email' => 'owner@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $other = User::query()->create([
            'name' => 'Other Owner',
            'email' => 'other@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();
        $token = str_repeat('a', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $owner->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);
        $otherBusiness = Business::query()->create([
            'name' => 'Other Business',
            'slug' => 'other-business-test',
            'country_code' => 'NG',
            'status' => 'pending_review',
            'created_by_user_id' => $other->id,
        ]);

        $this->withToken($token)->postJson('/api/v1/businesses', [
            'name' => 'Amina Thrift Services',
            'country_code' => 'NG',
        ])->assertCreated()->assertJsonPath('data.status', 'pending_review');

        $this->withToken($token)->getJson('/api/v1/business/current', [
            'X-Business-Id' => (string) $otherBusiness->id,
        ])->assertNotFound();
    }

    public function test_unverified_user_cannot_create_business(): void
    {
        $user = User::query()->create([
            'name' => 'Unverified',
            'email' => 'unverified@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $token = str_repeat('c', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token)->postJson('/api/v1/businesses', [
            'name' => 'Blocked Business',
            'country_code' => 'NG',
        ])->assertForbidden();
    }

    public function test_member_listing_is_permission_protected(): void
    {
        $this->seed();
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner2@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $contributor = User::query()->create([
            'name' => 'Contributor',
            'email' => 'contributor@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $contributor->forceFill(['email_verified_at' => now()])->save();
        $business = Business::query()->create([
            'name' => 'Role Test Business',
            'slug' => 'role-test-business',
            'country_code' => 'NG',
            'status' => 'active',
            'created_by_user_id' => $owner->id,
        ]);
        BusinessMembership::query()->create([
            'business_id' => $business->id, 'user_id' => $contributor->id,
            'role' => 'contributor', 'status' => 'active', 'joined_at' => now(),
        ]);
        $token = str_repeat('d', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $contributor->id, 'name' => 'test',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token)->getJson('/api/v1/business/members', [
            'X-Business-Id' => (string) $business->id,
        ])->assertForbidden();
        self::assertFalse(BusinessPermission::allows('contributor', BusinessPermission::ManageMembers->value));
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::query()->create([
            'name' => 'Expired User',
            'email' => 'expired@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $token = str_repeat('b', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'expired',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
        ]);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
    public function test_logout_revokes_the_presented_token_immediately(): void
    {
        $user = User::query()->create([
            'name' => 'Logout Token User',
            'email' => 'logout-token@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $plainToken = str_repeat('l', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'logout-test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($plainToken)->postJson('/api/v1/auth/logout')
            ->assertOk()->assertJsonPath('message', 'Signed out.');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);
        $this->withToken($plainToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

}
