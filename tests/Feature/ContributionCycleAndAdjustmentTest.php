<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessAuditEvent;
use App\Models\ContributionCycle;
use App\Models\ContributionRecord;
use App\Models\ContributionRecordAdjustment;
use App\Models\ContributionSchedule;
use App\Models\PersonalAccessToken;
use App\Models\ThriftGroup;
use App\Models\ThriftGroupMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContributionCycleAndAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user, string $value): string
    {
        PersonalAccessToken::query()->create([
            'user_id' => $user->id, 'name' => 'cycle-test',
            'token_hash' => hash('sha256', $value), 'expires_at' => now()->addDay(),
        ]);
        return $value;
    }

    private function groupWithTwoMembers(): array
    {
        $this->seed();
        $owner = User::query()->create([
            'name' => 'Schedule Owner', 'email' => 'schedule-owner@example.test',
            'password' => 'a-strong-test-password', 'email_verified_at' => now(),
        ]);
        $reviewer = User::query()->create([
            'name' => 'Independent Reviewer', 'email' => 'schedule-reviewer@example.test',
            'password' => 'a-strong-test-password', 'email_verified_at' => now(),
        ]);
        $business = Business::query()->create([
            'name' => 'Cycle Test Business', 'slug' => 'cycle-test-business',
            'country_code' => 'NG', 'status' => 'active', 'created_by_user_id' => $owner->id,
        ]);
        foreach ([$owner, $reviewer] as $user) {
            BusinessMembership::query()->create([
                'business_id' => $business->id, 'user_id' => $user->id,
                'role' => 'business_owner', 'status' => 'active', 'joined_at' => now(),
            ]);
        }
        $group = ThriftGroup::query()->create([
            'business_id' => $business->id, 'name' => 'Monthly Ajo',
            'currency_code' => 'NGN', 'status' => 'active', 'created_by_user_id' => $owner->id,
        ]);
        foreach ([$owner, $reviewer] as $user) {
            ThriftGroupMembership::query()->create([
                'thrift_group_id' => $group->id, 'user_id' => $user->id,
                'role' => $user->id === $owner->id ? 'organizer' : 'member',
                'status' => 'active', 'joined_at' => now(),
            ]);
        }
        return [$owner, $reviewer, $business, $group];
    }

    public function test_monthly_schedule_generates_idempotent_cycles_without_collecting_money(): void
    {
        [$owner, , $business, $group] = $this->groupWithTwoMembers();
        $token = $this->token($owner, str_repeat('e', 64));

        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups/'.$group->id.'/schedules', [
                'name' => 'Monthly Contribution', 'amount_minor' => 500000,
                'frequency' => 'monthly', 'starts_on' => '2026-01-01',
                'due_day_of_month' => 15,
            ])->assertCreated()->assertJsonPath('data.currency_code', 'NGN');

        $schedule = ContributionSchedule::query()->firstOrFail();
        $path = '/api/v1/business/groups/'.$group->id.'/schedules/'.$schedule->id.'/generate-cycles';
        $payload = ['from' => '2026-01-01', 'through' => '2026-03-31'];
        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson($path, $payload)->assertOk()
            ->assertJsonPath('data.created_count', 3)
            ->assertJsonPath('data.existing_count', 0);

        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson($path, $payload)->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.existing_count', 3);

        $this->assertSame(3, ContributionCycle::query()->count());
        $this->assertSame(0, ContributionRecord::query()->count());
        $this->assertDatabaseHas('business_audit_events', ['action' => 'contribution_cycles.generated', 'actor_user_id' => $owner->id]);
    }

    public function test_approved_reversal_request_is_audited_and_does_not_mutate_original_record(): void
    {
        [$owner, $reviewer, $business, $group] = $this->groupWithTwoMembers();
        $record = ContributionRecord::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $owner->id, 'recorded_by_user_id' => $reviewer->id,
            'amount_minor' => 87500, 'currency_code' => 'NGN', 'status' => 'confirmed',
            'collection_method' => 'cash', 'confirmed_by_user_id' => $owner->id, 'confirmed_at' => now(),
        ]);
        $ownerToken = $this->token($owner, str_repeat('f', 64));
        $reviewerToken = $this->token($reviewer, str_repeat('g', 64));

        $this->withToken($ownerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups/'.$group->id.'/contributions/'.$record->id.'/adjustments', [
                'adjustment_type' => 'reversal', 'reason' => 'The collection was recorded against the wrong member.',
            ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $adjustment = ContributionRecordAdjustment::query()->firstOrFail();
        $this->withToken($ownerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups/'.$group->id.'/adjustments/'.$adjustment->id.'/decide', [
                'decision' => 'approved', 'decision_note' => 'Reviewed the evidence independently and approved the request.',
            ])->assertForbidden();

        $this->withToken($reviewerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups/'.$group->id.'/adjustments/'.$adjustment->id.'/decide', [
                'decision' => 'approved', 'decision_note' => 'Reviewed the evidence independently and approved the request.',
            ])->assertOk();

        $this->assertDatabaseHas('contribution_records', [
            'id' => $record->id, 'status' => 'confirmed', 'amount_minor' => 87500,
        ]);
        $this->assertDatabaseHas('contribution_record_adjustments', [
            'id' => $adjustment->id, 'status' => 'approved', 'decided_by_user_id' => $reviewer->id,
        ]);
        $this->assertDatabaseHas('business_audit_events', ['action' => 'contribution_adjustment.approved', 'actor_user_id' => $reviewer->id]);
    }
}
