<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\ContributionRecord;
use App\Models\PersonalAccessToken;
use App\Models\ThriftGroup;
use App\Models\ThriftGroupMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ThriftDashboardAndPlatformAdminTest extends TestCase
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

    private function makeBusinessAndMembership(User $user): Business
    {
        $business = Business::query()->create([
            'name' => 'Thrift Test Business',
            'slug' => 'thrift-test-'.substr(hash('sha256', $user->email), 0, 8),
            'country_code' => 'NG',
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);
        BusinessMembership::query()->create([
            'business_id' => $business->id, 'user_id' => $user->id,
            'role' => 'business_owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $business;
    }

    public function test_dashboard_returns_only_the_signed_in_members_groups_and_contributions(): void
    {
        $this->seed();
        $user = User::query()->create([
            'name' => 'Group Member', 'email' => 'groups@example.test',
            'password' => 'a-strong-test-password', 'email_verified_at' => now(),
        ]);
        $business = $this->makeBusinessAndMembership($user);
        $group = ThriftGroup::query()->create([
            'business_id' => $business->id, 'name' => 'Weekly Ajo',
            'currency_code' => 'NGN', 'status' => 'active', 'created_by_user_id' => $user->id,
        ]);
        ThriftGroupMembership::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $user->id,
            'role' => 'organizer', 'status' => 'active', 'joined_at' => now(),
        ]);
        $collector = User::query()->create([
            'name' => 'Independent Collector', 'email' => 'collector@example.test',
            'password' => 'a-strong-test-password', 'email_verified_at' => now(),
        ]);
        ThriftGroupMembership::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $collector->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        ContributionRecord::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $user->id, 'recorded_by_user_id' => $collector->id,
            'amount_minor' => 250000, 'currency_code' => 'NGN', 'status' => 'confirmed',
            'collection_method' => 'cash', 'confirmed_by_user_id' => $collector->id, 'confirmed_at' => now(),
        ]);
        $token = $this->tokenFor($user, str_repeat('a', 64));

        $this->withToken($token)->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.active_groups_count', 1)
            ->assertJsonPath('data.groups.0.name', 'Weekly Ajo')
            ->assertJsonPath('data.confirmed_contribution_totals.0.currency_code', 'NGN')
            ->assertJsonPath('data.confirmed_contribution_totals.0.total_minor', 250000)
            ->assertJsonPath('data.recent_contributions.0.amount_minor', 250000);
    }

    public function test_group_creation_is_scoped_and_audited(): void
    {
        $this->seed();
        $user = User::query()->create([
            'name' => 'Organizer', 'email' => 'organizer@example.test',
            'password' => 'a-strong-test-password', 'email_verified_at' => now(),
        ]);
        $business = $this->makeBusinessAndMembership($user);
        $token = $this->tokenFor($user, str_repeat('b', 64));

        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups', [
                'name' => 'Monthly Ajo', 'currency_code' => 'NGN',
            ])->assertCreated()->assertJsonPath('data.name', 'Monthly Ajo');

        $this->assertDatabaseHas('thrift_group_memberships', ['user_id' => $user->id, 'role' => 'organizer', 'status' => 'active']);
        $this->assertDatabaseHas('business_audit_events', ['action' => 'thrift_group.created', 'actor_user_id' => $user->id]);
    }

    public function test_admin_provisioning_requires_explicit_permission_and_writes_audit_event(): void
    {
        $actor = User::query()->create([
            'name' => 'Platform Operator', 'email' => 'operator@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $actor->forceFill(['platform_role' => 'admin', 'platform_permissions' => ['platform.admins.manage']])->save();
        $target = User::query()->create([
            'name' => 'New Admin', 'email' => 'newadmin@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $token = $this->tokenFor($actor, str_repeat('c', 64));

        $this->withToken($token)->postJson('/api/v1/admin/users/'.$target->id.'/provision', [
            'platform_role' => 'admin',
            'permissions' => ['platform.audit.view'],
            'reason' => 'Approved by the platform security owner.',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'platform_role' => 'admin']);
        $this->assertDatabaseHas('platform_audit_events', [
            'actor_user_id' => $actor->id, 'subject_user_id' => $target->id,
            'action' => 'platform_admin.provisioned',
        ]);
    }

    public function test_platform_admin_has_full_admin_module_access_without_permission_allowlists(): void
    {
        $actor = User::query()->create([
            'name' => 'Full Access Administrator', 'email' => 'full-admin@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $actor->forceFill(['platform_role' => 'admin', 'platform_permissions' => []])->save();
        $token = $this->tokenFor($actor, str_repeat('z', 64));

        $this->withToken($token)->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.status', 'authorized_full_access')
            ->assertJsonPath('data.access_mode', 'full_platform_admin')
            ->assertJsonPath('data.financial_execution_enabled', false)
            ->assertJsonPath('data.available_modules.0', 'members');

        $this->withToken($token)->getJson('/api/v1/admin/audit-events')->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin/security-settings')->assertOk();
    }

    public function test_platform_admin_can_manage_business_modules_without_membership_and_access_is_audited(): void
    {
        $this->seed();
        $actor = User::query()->create([
            'name' => 'Cross-Business Administrator', 'email' => 'cross-business-admin@example.test',
            'password' => 'a-strong-test-password', 'email_verified_at' => now(),
        ]);
        $actor->forceFill(['platform_role' => 'admin', 'platform_permissions' => [], 'email_verified_at' => now()])->save();
        $business = Business::query()->create([
            'name' => 'Admin Managed Business', 'slug' => 'admin-managed-business',
            'country_code' => 'NG', 'status' => 'active', 'created_by_user_id' => $actor->id,
        ]);
        $group = ThriftGroup::query()->create([
            'business_id' => $business->id, 'name' => 'Group Managed By Admin',
            'currency_code' => 'NGN', 'status' => 'active', 'created_by_user_id' => $actor->id,
        ]);
        $token = $this->tokenFor($actor, str_repeat('q', 64));

        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/v1/business/payments/gateways')
            ->assertOk()
            ->assertJsonPath('data.0.ready_for_live_execution', false);

        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/v1/business/groups')
            ->assertOk()->assertJsonPath('data.data.0.id', $group->id);
        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/v1/business/groups/'.$group->id.'/contributions')
            ->assertOk();

        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->putJson('/api/v1/business/payments/gateways/manual/deposit', [
                'public_config' => ['label' => 'Admin-managed manual review'],
            ])->assertOk()->assertJsonPath('data.status', 'disabled');

        $this->assertDatabaseHas('business_audit_events', [
            'business_id' => $business->id,
            'actor_user_id' => $actor->id,
            'action' => 'platform_admin.business_operation_attempted',
        ]);
        $this->assertDatabaseHas('payment_operation_events', [
            'business_id' => $business->id,
            'actor_user_id' => $actor->id,
            'event' => 'gateway_configured_disabled',
        ]);
        $this->assertDatabaseMissing('business_memberships', [
            'business_id' => $business->id, 'user_id' => $actor->id,
        ]);
    }

    public function test_admin_cannot_provision_self_but_can_manage_other_admins_without_permission_allowlist(): void
    {
        $actor = User::query()->create([
            'name' => 'Restricted Admin', 'email' => 'restricted@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $actor->forceFill(['platform_role' => 'admin', 'platform_permissions' => []])->save();
        $token = $this->tokenFor($actor, str_repeat('d', 64));

        $this->withToken($token)->postJson('/api/v1/admin/users/'.$actor->id.'/provision', [
            'platform_role' => 'member', 'reason' => 'Self role change should be denied.',
        ])->assertForbidden();

        $target = User::query()->create([
            'name' => 'Target User', 'email' => 'target@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $this->withToken($token)->postJson('/api/v1/admin/users/'.$target->id.'/provision', [
            'platform_role' => 'admin', 'reason' => 'Full platform access granted to the approved operator.',
        ])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'platform_role' => 'admin']);
        $this->assertDatabaseHas('platform_audit_events', [
            'actor_user_id' => $actor->id, 'subject_user_id' => $target->id,
            'action' => 'platform_admin.provisioned',
        ]);
    }
}
