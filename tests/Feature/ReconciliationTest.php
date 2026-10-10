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

final class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed();
        $user = User::query()->create([
            'name' => 'Reconciliation Owner', 'email' => 'recon-owner@example.test',
            'password' => 'a-strong-test-password',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $business = Business::query()->create([
            'name' => 'Reconciliation Business', 'slug' => 'reconciliation-business',
            'country_code' => 'NG', 'status' => 'active', 'created_by_user_id' => $user->id,
        ]);
        BusinessMembership::query()->create([
            'business_id' => $business->id, 'user_id' => $user->id,
            'role' => 'business_owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $group = ThriftGroup::query()->create([
            'business_id' => $business->id, 'name' => 'Recon Group',
            'currency_code' => 'NGN', 'status' => 'active', 'created_by_user_id' => $user->id,
        ]);
        ThriftGroupMembership::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $user->id,
            'role' => 'organizer', 'status' => 'active', 'joined_at' => now(),
        ]);
        $token = str_repeat('r', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $user->id, 'name' => 'reconciliation-test',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(),
        ]);
        return [$user, $business, $group, $token];
    }

    public function test_duplicate_rows_are_flagged_and_replayed_statement_is_idempotent(): void
    {
        [, $business, , $token] = $this->fixture();
        $payload = [
            'source' => 'bank_statement', 'source_reference' => 'bank-file-001', 'currency_code' => 'NGN',
            'entries' => [
                ['source_transaction_id' => 'bank-tx-1', 'reference' => 'REF-100', 'amount_minor' => 5000, 'occurred_at' => now()->toDateTimeString()],
                ['source_transaction_id' => 'bank-tx-1', 'reference' => 'REF-100', 'amount_minor' => 5000, 'occurred_at' => now()->toDateTimeString()],
            ],
        ];
        $response = $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/reconciliation/batches', $payload)->assertCreated();
        $response->assertJsonPath('data.entries.0.status', 'unmatched')
            ->assertJsonPath('data.entries.1.status', 'duplicate');
        $this->assertDatabaseCount('reconciliation_entries', 2);

        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/reconciliation/batches', $payload)
            ->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('reconciliation_batches', 1);
        $this->assertDatabaseCount('reconciliation_entries', 2);
    }

    public function test_amount_mismatch_cannot_be_matched_and_decision_is_audited(): void
    {
        [$user, $business, $group, $token] = $this->fixture();
        $record = ContributionRecord::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $user->id, 'recorded_by_user_id' => $user->id,
            'amount_minor' => 9000, 'currency_code' => 'NGN', 'status' => 'confirmed',
            'collection_method' => 'bank_transfer', 'reference' => 'REF-200',
            'confirmed_by_user_id' => $user->id, 'confirmed_at' => now(),
        ]);
        $batch = $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/reconciliation/batches', [
                'source' => 'provider_statement', 'source_reference' => 'provider-file-002', 'currency_code' => 'NGN',
                'entries' => [[
                    'source_transaction_id' => 'provider-tx-2', 'reference' => 'REF-200',
                    'amount_minor' => 9001, 'occurred_at' => now()->toDateTimeString(),
                ]],
            ])->assertCreated();
        $entryId = $batch->json('data.entries.0.id');
        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/reconciliation/entries/'.$entryId.'/decision', [
                'decision' => 'match', 'contribution_record_id' => $record->id,
                'reason' => 'Investigating an amount discrepancy in the provider statement.',
            ])->assertStatus(422);

        $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/reconciliation/entries/'.$entryId.'/decision', [
                'decision' => 'conflict', 'reason' => 'External amount does not equal the recorded contribution.',
            ])->assertOk()->assertJsonPath('data.status', 'conflict');
        $this->assertDatabaseHas('reconciliation_decisions', [
            'reconciliation_entry_id' => $entryId, 'actor_user_id' => $user->id, 'decision' => 'conflict',
        ]);
        $this->assertDatabaseHas('contribution_records', ['id' => $record->id, 'amount_minor' => 9000, 'status' => 'confirmed']);
    }
}
