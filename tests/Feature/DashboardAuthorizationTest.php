<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_dashboard_returns_only_the_current_users_profile_and_active_workspaces(): void
    {
        $this->seed();
        $user = User::query()->create([
            'name' => 'Dashboard Member',
            'email' => 'dashboard@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $business = Business::query()->create([
            'name' => 'Member Workspace',
            'slug' => 'member-workspace',
            'country_code' => 'NG',
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);
        BusinessMembership::query()->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $token = str_repeat('x', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token)->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.active_workspaces_count', 1)
            ->assertJsonPath('data.workspaces.0.name', 'Member Workspace')
            ->assertJsonPath('data.financial_data_status', 'not_available');
    }

    public function test_dashboard_summary_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard/summary')->assertUnauthorized();
    }

    public function test_non_admin_cannot_access_admin_dashboard_api(): void
    {
        $user = User::query()->create([
            'name' => 'Regular Member',
            'email' => 'regular@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $token = str_repeat('y', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_platform_admin_can_access_admin_dashboard_preview_api(): void
    {
        $admin = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $admin->forceFill(['platform_role' => 'admin'])->save();
        $token = str_repeat('z', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $admin->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token)->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.status', 'authorized_preview');
    }
}
