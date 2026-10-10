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
            'email_verified_at' => now(),
        ]);
        $other = User::query()->create([
            'name' => 'Other Owner',
            'email' => 'other@example.test',
            'password' => 'a-strong-test-password',
            'email_verified_at' => now(),
        ]);
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
            'email_verified_at' => now(),
        ]);
        $contributor = User::query()->create([
            'name' => 'Contributor',
            'email' => 'contributor@example.test',
            'password' => 'a-strong-test-password',
            'email_verified_at' => now(),
        ]);
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
}
