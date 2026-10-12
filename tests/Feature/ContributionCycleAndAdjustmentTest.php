<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessAuditEvent;
use App\Models\ContributionCycle;
use App\Models\ContributionCycleObligation;
use App\Models\ContributionRecord;
use App\Models\ContributionRecordAdjustment;
use App\Models\ContributionSchedule;
use App\Models\PersonalAccessToken;
use App\Models\ThriftGroup;
use App\Models\ThriftGroupMembership;
use App\Models\User;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $owner->forceFill(['email_verified_at' => now()])->save();
        $reviewer->forceFill(['email_verified_at' => now()])->save();
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
                'frequency' => 'monthly', 'starts_on' => '2027-01-01',
                'due_day_of_month' => 15,
            ])->assertCreated()->assertJsonPath('data.currency_code', 'NGN');

        $schedule = ContributionSchedule::query()->firstOrFail();
        $path = '/api/v1/business/groups/'.$group->id.'/schedules/'.$schedule->id.'/generate-cycles';
        $payload = ['from' => '2027-01-01', 'through' => '2027-03-31'];
        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson($path, $payload)->assertOk()
            ->assertJsonPath('data.created_count', 3)
            ->assertJsonPath('data.existing_count', 0)
            ->assertJsonPath('data.obligations_created_count', 6);

        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson($path, $payload)->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.existing_count', 3)
            ->assertJsonPath('data.obligations_created_count', 0);

        $this->assertSame(3, ContributionCycle::query()->count());
        $this->assertSame(6, ContributionCycleObligation::query()->count());
        $this->withToken($token)->getJson('/api/v1/dashboard/summary')->assertOk()
            ->assertJsonPath('data.upcoming_obligations_count', 3)
            ->assertJsonPath('data.upcoming_obligations.0.expected_amount_minor', 500000);
        $this->assertSame(0, ContributionRecord::query()->count());
        $this->assertDatabaseHas('business_audit_events', ['action' => 'contribution_cycles.generated', 'actor_user_id' => $owner->id]);
    }

    public function test_contribution_confirmation_requires_a_different_group_member_and_is_single_use(): void
    {
        [$owner, $reviewer, $business, $group] = $this->groupWithTwoMembers();
        $record = ContributionRecord::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $owner->id, 'recorded_by_user_id' => $owner->id,
            'amount_minor' => 12500, 'currency_code' => 'NGN', 'status' => 'recorded',
            'collection_method' => 'cash',
        ]);
        $ownerToken = $this->token($owner, str_repeat('j', 64));
        $reviewerToken = $this->token($reviewer, str_repeat('k', 64));
        $path = '/api/v1/business/groups/'.$group->id.'/contributions/'.$record->id.'/confirm';

        $this->withToken($ownerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson($path)->assertForbidden();

        $this->withToken($reviewerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson($path)->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->withToken($reviewerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson($path)->assertStatus(409);

        $this->assertDatabaseHas('contribution_records', [
            'id' => $record->id, 'status' => 'confirmed',
            'confirmed_by_user_id' => $reviewer->id, 'amount_minor' => 12500,
        ]);
        $this->assertSame(1, BusinessAuditEvent::query()
            ->where('action', 'contribution.confirmed')->where('subject_id', $record->id)->count());
    }

    public function test_approved_reversal_request_is_audited_and_does_not_mutate_original_record(): void
    {
        [$owner, $reviewer, $business, $group] = $this->groupWithTwoMembers();
        $record = ContributionRecord::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $owner->id, 'recorded_by_user_id' => $reviewer->id,
            'amount_minor' => 87500, 'currency_code' => 'NGN', 'status' => 'confirmed',
            'collection_method' => 'cash', 'confirmed_by_user_id' => $owner->id, 'confirmed_at' => now(),
        ]);
        $originalPosting = app(WalletLedgerService::class)->creditContribution(
            (int) $business->id, (int) $owner->id, 'NGN', 87500, (int) $record->id, (int) $reviewer->id
        );
        $this->assertSame('posted', $originalPosting['status']);
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

        $outsider = User::query()->create([
            'name' => 'Business Manager Outside Group', 'email' => 'outsider@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $outsider->forceFill(['email_verified_at' => now()])->save();
        BusinessMembership::query()->create([
            'business_id' => $business->id, 'user_id' => $outsider->id,
            'role' => 'business_manager', 'status' => 'active', 'joined_at' => now(),
        ]);
        $outsiderToken = $this->token($outsider, str_repeat('h', 64));
        $this->withToken($outsiderToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups/'.$group->id.'/adjustments/'.$adjustment->id.'/decide', [
                'decision' => 'approved', 'decision_note' => 'Attempt to decide outside the group membership boundary.',
            ])->assertForbidden();

        $this->withToken($reviewerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups/'.$group->id.'/adjustments/'.$adjustment->id.'/decide', [
                'decision' => 'approved', 'decision_note' => 'Reviewed the evidence independently and approved the request.',
            ])->assertOk();

        $this->assertDatabaseHas('contribution_records', [
            'id' => $record->id, 'status' => 'reversed', 'amount_minor' => 87500,
        ]);
        $this->assertDatabaseHas('contribution_record_adjustments', [
            'id' => $adjustment->id, 'status' => 'approved', 'decided_by_user_id' => $reviewer->id,
        ]);
        $this->assertSame(2, DB::table('wallet_ledger_transactions')->where('business_id', $business->id)->count());
        $this->assertSame(4, DB::table('wallet_ledger_entries')->count());
        $this->assertSame(0, app(WalletLedgerService::class)->balance((int) $business->id, (int) $owner->id, 'NGN'));
        $this->assertDatabaseHas('business_audit_events', ['action' => 'contribution_adjustment.approved', 'actor_user_id' => $reviewer->id]);
    }

    public function test_reversal_cannot_be_approved_when_member_wallet_lacks_full_amount(): void
    {
        [$owner, $reviewer, $business, $group] = $this->groupWithTwoMembers();
        $record = ContributionRecord::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $owner->id, 'recorded_by_user_id' => $reviewer->id,
            'amount_minor' => 87500, 'currency_code' => 'NGN', 'status' => 'confirmed',
            'collection_method' => 'cash', 'confirmed_by_user_id' => $owner->id, 'confirmed_at' => now(),
        ]);
        $ownerToken = $this->token($owner, str_repeat('l', 64));
        $reviewerToken = $this->token($reviewer, str_repeat('m', 64));

        $this->withToken($ownerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups/'.$group->id.'/contributions/'.$record->id.'/adjustments', [
                'adjustment_type' => 'reversal', 'reason' => 'The original collection was recorded incorrectly.',
            ])->assertCreated();
        $adjustment = ContributionRecordAdjustment::query()->firstOrFail();

        $this->withToken($reviewerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups/'.$group->id.'/adjustments/'.$adjustment->id.'/decide', [
                'decision' => 'approved', 'decision_note' => 'Evidence reviewed independently before decision.',
            ])->assertStatus(409)->assertJsonPath('code', 'contribution_reversal_insufficient_balance');

        $this->assertDatabaseHas('contribution_records', ['id' => $record->id, 'status' => 'confirmed', 'amount_minor' => 87500]);
        $this->assertDatabaseHas('contribution_record_adjustments', ['id' => $adjustment->id, 'status' => 'pending']);
        $this->assertDatabaseCount('wallet_ledger_transactions', 0);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
    }


    public function test_approved_contribution_correction_posts_only_the_amount_delta(): void
    {
        [$owner, $reviewer, $business, $group] = $this->groupWithTwoMembers();
        $record = ContributionRecord::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $owner->id, 'recorded_by_user_id' => $reviewer->id,
            'amount_minor' => 87500, 'currency_code' => 'NGN', 'status' => 'confirmed',
            'collection_method' => 'cash', 'confirmed_by_user_id' => $owner->id, 'confirmed_at' => now(),
        ]);
        $originalPosting = app(WalletLedgerService::class)->creditContribution(
            (int) $business->id, (int) $owner->id, 'NGN', 87500, (int) $record->id, (int) $reviewer->id
        );
        $this->assertSame('posted', $originalPosting['status']);
        $ownerToken = $this->token($owner, str_repeat('n', 64));
        $reviewerToken = $this->token($reviewer, str_repeat('o', 64));

        $this->withToken($ownerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups/'.$group->id.'/contributions/'.$record->id.'/adjustments', [
                'adjustment_type' => 'correction',
                'proposed_amount_minor' => 100000,
                'reason' => 'The original recorded contribution amount was understated.',
            ])->assertCreated();
        $adjustment = ContributionRecordAdjustment::query()->firstOrFail();

        $this->withToken($reviewerToken)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/groups/'.$group->id.'/adjustments/'.$adjustment->id.'/decide', [
                'decision' => 'approved', 'decision_note' => 'Verified the receipt and approved the amount correction.',
            ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('contribution_records', ['id' => $record->id, 'status' => 'confirmed', 'amount_minor' => 100000]);
        $this->assertSame(100000, app(WalletLedgerService::class)->balance((int) $business->id, (int) $owner->id, 'NGN'));
        $this->assertSame(2, DB::table('wallet_ledger_transactions')->where('business_id', $business->id)->count());
        $this->assertSame(4, DB::table('wallet_ledger_entries')->count());
        $this->assertDatabaseHas('business_audit_events', [
            'action' => 'contribution_adjustment.approved',
            'subject_id' => $adjustment->id,
        ]);
    }

}
