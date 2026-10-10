<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_returns_a_bearer_token_but_only_hash_is_stored(): void
    {
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
    }

    public function test_business_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/businesses')->assertUnauthorized();
    }

    public function test_user_can_create_business_and_access_only_a_business_they_belong_to(): void
    {
        $this->seed();
        $owner = User::query()->create([
            'name' => 'Business Owner',
            'email' => 'owner@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $other = User::query()->create([
            'name' => 'Other Owner',
            'email' => 'other@example.test',
            'password' => 'a-strong-test-password',
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
