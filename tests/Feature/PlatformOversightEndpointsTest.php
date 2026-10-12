<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformOversightEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINTS = [
        '/api/v1/admin/businesses' => 'platform.businesses.view',
        '/api/v1/admin/collections' => 'platform.collections.view',
        '/api/v1/admin/reconciliation' => 'platform.reconciliation.view',
        '/api/v1/admin/approvals' => 'platform.approvals.view',
    ];

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

    private function admin(array $permissions, string $email): array
    {
        $user = User::query()->create([
            'name' => 'Platform Oversight Admin',
            'email' => $email,
            'password' => 'a-strong-test-password',
        ]);
        $user->forceFill(['platform_role' => 'admin', 'platform_permissions' => $permissions])->save();
        $token = str_repeat(substr(hash('sha256', $email), 0, 1), 64);

        return [$user, $this->tokenFor($user, $token), $token];
    }

    public function test_each_platform_oversight_endpoint_requires_its_specific_permission(): void
    {
        [$admin, $unused, $token] = $this->admin([], 'restricted-oversight@example.test');

        foreach (self::ENDPOINTS as $path => $permission) {
            $this->withToken($token)->getJson($path)->assertForbidden();
        }
    }

    public function test_authorized_oversight_endpoints_return_read_only_data_and_do_not_mutate_records(): void
    {
        $this->seed();
        [$admin, $unused, $token] = $this->admin(array_values(self::ENDPOINTS), 'oversight-authorized@example.test');
        $business = Business::query()->create([
            'name' => 'Oversight Test Business',
            'slug' => 'oversight-test-business',
            'country_code' => 'NG',
            'status' => 'active',
            'created_by_user_id' => $admin->id,
        ]);

        $this->withToken($token)->getJson('/api/v1/admin/businesses?q=Oversight')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $business->id)
            ->assertJsonPath('data.data.0.name', 'Oversight Test Business')
            ->assertJsonMissingPath('data.data.0.settings');

        $this->withToken($token)->getJson('/api/v1/admin/collections')
            ->assertOk()
            ->assertJsonPath('data.read_only', true)
            ->assertJsonStructure(['data' => ['contributions', 'deposits', 'payouts']]);

        $this->withToken($token)->getJson('/api/v1/admin/reconciliation')
            ->assertOk()
            ->assertJsonPath('data.read_only', true)
            ->assertJsonStructure(['data' => ['batches', 'unresolved_exceptions']]);

        $this->withToken($token)->getJson('/api/v1/admin/approvals')
            ->assertOk()
            ->assertJsonPath('data.read_only', true)
            ->assertJsonStructure(['data' => ['pending_deposits', 'pending_payout_decisions', 'pending_contribution_adjustments']]);

        $this->assertDatabaseCount('businesses', 1);
        $this->assertDatabaseCount('platform_audit_events', 0);
    }

    public function test_platform_permission_grants_reject_unknown_or_unapproved_permissions(): void
    {
        [$admin, $unused, $token] = $this->admin(['platform.admins.manage'], 'permission-manager@example.test');
        $target = User::query()->create([
            'name' => 'Target Member',
            'email' => 'permission-target@example.test',
            'password' => 'a-strong-test-password',
        ]);

        $this->withToken($token)->postJson('/api/v1/admin/users/'.$target->id.'/provision', [
            'platform_role' => 'admin',
            'permissions' => ['platform.approvals.view', 'platform.reconciliation.view'],
            'reason' => 'Grant read-only oversight access.',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'platform_role' => 'admin']);
        $this->assertDatabaseHas('platform_audit_events', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $target->id,
            'action' => 'platform_admin.provisioned',
        ]);

        $this->withToken($token)->postJson('/api/v1/admin/users/'.$target->id.'/provision', [
            'platform_role' => 'admin',
            'permissions' => ['platform.payments.execute'],
            'reason' => 'Unknown permissions must never be granted.',
        ])->assertUnprocessable();
    }
}
