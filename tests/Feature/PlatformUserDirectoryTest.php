<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformUserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user, string $token): string
    {
        PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'feature-test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    public function test_platform_user_directory_requires_the_users_view_permission(): void
    {
        $admin = User::query()->create([
            'name' => 'Restricted Admin',
            'email' => 'restricted-admin@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $admin->forceFill(['platform_role' => 'admin', 'platform_permissions' => []])->save();
        $token = $this->tokenFor($admin, str_repeat('e', 64));

        $this->withToken($token)->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_platform_user_directory_searches_and_returns_only_safe_paged_fields(): void
    {
        $admin = User::query()->create([
            'name' => 'Platform Operator',
            'email' => 'operator@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $admin->forceFill(['platform_role' => 'admin', 'platform_permissions' => ['platform.users.view']])->save();

        $target = User::query()->create([
            'name' => 'Member One',
            'email' => 'member-one@example.test',
            'password' => 'a-strong-test-password',
            'email_verified_at' => now(),
        ]);
        $this->tokenFor($admin, str_repeat('f', 64));

        $this->withToken(str_repeat('f', 64))->getJson('/api/v1/admin/users?q=member-one&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.data.0.id', $target->id)
            ->assertJsonPath('data.data.0.email', 'member-one@example.test')
            ->assertJsonMissingPath('data.data.0.password')
            ->assertJsonMissingPath('data.data.0.platform_permissions');
    }

    public function test_platform_user_directory_validates_pagination_bounds(): void
    {
        $admin = User::query()->create([
            'name' => 'Platform Operator',
            'email' => 'operator-validation@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $admin->forceFill(['platform_role' => 'admin', 'platform_permissions' => ['platform.users.view']])->save();
        $token = $this->tokenFor($admin, str_repeat('g', 64));

        $this->withToken($token)->getJson('/api/v1/admin/users?per_page=500')->assertUnprocessable();
    }
}
