<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessAuditEvent;
use App\Models\BusinessMembership;
use App\Models\BusinessMembershipInvitation;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Notifications\BusinessMembershipInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class BusinessMembershipManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_a_member_and_only_a_token_hash_is_persisted(): void
    {
        Notification::fake();
        [$owner, $token, $business] = $this->ownerAndBusiness();

        $response = $this->withToken($token)->postJson('/api/v1/business/invitations', [
            'email' => 'new.member@example.test',
            'role' => 'field_agent',
            'expires_in_days' => 3,
        ], ['X-Business-Id' => (string) $business->id]);

        $response->assertCreated()->assertJsonPath('data.role', 'field_agent');
        $invitation = BusinessMembershipInvitation::query()->firstOrFail();
        self::assertSame(64, strlen($invitation->token_hash));
        self::assertNotSame('new.member@example.test', $invitation->token_hash);
        $this->assertDatabaseHas('business_audit_events', [
            'business_id' => $business->id,
            'actor_user_id' => $owner->id,
            'action' => 'membership.invitation.created',
            'subject_id' => $invitation->id,
        ]);
        Notification::assertSentOnDemand(BusinessMembershipInvitationNotification::class);
    }

    public function test_manager_cannot_invite_another_manager_or_owner(): void
    {
        [$owner, $ownerToken, $business] = $this->ownerAndBusiness();
        $manager = $this->verifiedUser('manager@example.test');
        BusinessMembership::query()->create([
            'business_id' => $business->id, 'user_id' => $manager->id,
            'role' => 'business_manager', 'status' => 'active', 'joined_at' => now(),
        ]);
        $managerToken = $this->tokenFor($manager);

        foreach (['business_owner', 'business_manager'] as $role) {
            $this->withToken($managerToken)->postJson('/api/v1/business/invitations', [
                'email' => 'new.member@example.test', 'role' => $role,
            ], ['X-Business-Id' => (string) $business->id])->assertForbidden();
        }
    }

    public function test_invitation_acceptance_requires_matching_verified_email_and_is_one_time(): void
    {
        $businessOwner = $this->verifiedUser('owner-accept@example.test');
        $business = $this->businessFor($businessOwner);
        $invited = $this->verifiedUser('invited@example.test');
        $wrongUser = $this->verifiedUser('wrong@example.test');
        $plainToken = str_repeat('t', 64);
        $invitation = $this->invitation($business, $businessOwner, 'invited@example.test', $plainToken);

        $this->withToken($this->tokenFor($wrongUser))->postJson('/api/v1/business/invitations/accept', [
            'token' => $plainToken,
        ])->assertForbidden();
        $this->assertDatabaseMissing('business_memberships', ['business_id' => $business->id, 'user_id' => $wrongUser->id]);

        $this->withToken($this->tokenFor($invited))->postJson('/api/v1/business/invitations/accept', [
            'token' => $plainToken,
        ])->assertOk()->assertJsonPath('business_id', $business->id);
        $this->assertDatabaseHas('business_memberships', [
            'business_id' => $business->id, 'user_id' => $invited->id,
            'role' => 'field_agent', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('business_audit_events', [
            'business_id' => $business->id, 'actor_user_id' => $invited->id,
            'action' => 'membership.invitation.accepted',
        ]);

        $this->withToken($this->tokenFor($invited))->postJson('/api/v1/business/invitations/accept', [
            'token' => $plainToken,
        ])->assertStatus(410);
    }

    public function test_expired_or_revoked_invitation_cannot_be_accepted(): void
    {
        $owner = $this->verifiedUser('owner-expiry@example.test');
        $business = $this->businessFor($owner);
        $invitee = $this->verifiedUser('expiry@example.test');
        $token = str_repeat('x', 64);
        $this->invitation($business, $owner, $invitee->email, $token, now()->subMinute());

        $this->withToken($this->tokenFor($invitee))->postJson('/api/v1/business/invitations/accept', [
            'token' => $token,
        ])->assertStatus(410);
        $this->assertDatabaseMissing('business_memberships', ['business_id' => $business->id, 'user_id' => $invitee->id]);
    }

    public function test_member_role_changes_are_tenant_scoped_and_audited(): void
    {
        [$owner, $ownerToken, $business] = $this->ownerAndBusiness();
        $member = $this->verifiedUser('role-member@example.test');
        $membership = BusinessMembership::query()->create([
            'business_id' => $business->id, 'user_id' => $member->id,
            'role' => 'field_agent', 'status' => 'active', 'joined_at' => now(),
        ]);
        $otherOwner = $this->verifiedUser('other-owner@example.test');
        $otherBusiness = $this->businessFor($otherOwner);
        $otherMember = $this->verifiedUser('other-member@example.test');
        $otherMembership = BusinessMembership::query()->create([
            'business_id' => $otherBusiness->id, 'user_id' => $otherMember->id,
            'role' => 'field_agent', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->withToken($ownerToken)->patchJson('/api/v1/business/members/'.$otherMembership->id.'/role', [
            'role' => 'auditor',
        ], ['X-Business-Id' => (string) $business->id])->assertNotFound();

        $this->withToken($ownerToken)->patchJson('/api/v1/business/members/'.$membership->id.'/role', [
            'role' => 'auditor',
        ], ['X-Business-Id' => (string) $business->id])->assertOk()->assertJsonPath('data.role', 'auditor');
        $this->assertDatabaseHas('business_audit_events', [
            'business_id' => $business->id, 'actor_user_id' => $owner->id,
            'action' => 'membership.role.changed', 'subject_id' => $membership->id,
        ]);
    }

    public function test_owner_cannot_be_demoted_or_removed_by_membership_endpoints(): void
    {
        [$owner, $ownerToken, $business] = $this->ownerAndBusiness();

        $this->withToken($ownerToken)->patchJson('/api/v1/business/members/'.$business->memberships()->where('user_id', $owner->id)->value('id').'/role', [
            'role' => 'auditor',
        ], ['X-Business-Id' => (string) $business->id])->assertForbidden();

        $this->withToken($ownerToken)->deleteJson('/api/v1/business/members/'.$business->memberships()->where('user_id', $owner->id)->value('id'), [], [
            'X-Business-Id' => (string) $business->id,
        ])->assertForbidden();
        self::assertSame('business_owner', $business->memberships()->where('user_id', $owner->id)->value('role'));
    }

    private function ownerAndBusiness(): array
    {
        $owner = $this->verifiedUser('owner-'.uniqid().'@example.test');
        $business = $this->businessFor($owner);
        $token = $this->tokenFor($owner);

        return [$owner, $token, $business];
    }

    private function businessFor(User $owner): Business
    {
        $this->seed();
        $business = Business::query()->create([
            'name' => 'Test Thrift Business',
            'slug' => 'thrift-'.strtolower(substr(sha1($owner->email), 0, 10)),
            'country_code' => 'NG',
            'status' => 'active',
            'created_by_user_id' => $owner->id,
        ]);
        BusinessMembership::query()->create([
            'business_id' => $business->id, 'user_id' => $owner->id,
            'role' => 'business_owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        return $business;
    }

    private function verifiedUser(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'a-strong-test-password',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function tokenFor(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        PersonalAccessToken::query()->create([
            'user_id' => $user->id,
            'name' => 'membership-test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function invitation(Business $business, User $inviter, string $email, string $plainToken, $expiresAt = null): BusinessMembershipInvitation
    {
        return BusinessMembershipInvitation::query()->create([
            'business_id' => $business->id,
            'email' => $email,
            'role' => 'field_agent',
            'token_hash' => hash('sha256', $plainToken),
            'invited_by_user_id' => $inviter->id,
            'expires_at' => $expiresAt ?? now()->addDays(7),
        ]);
    }
}
