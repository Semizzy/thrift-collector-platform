<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\ContributionRecord;
use App\Models\PersonalAccessToken;
use App\Models\ThriftGroup;
use App\Models\ThriftGroupMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $statuses = collect($response->json('data.entries'))->pluck('status')->sort()->values()->all();
        $this->assertSame(['duplicate', 'unmatched'], $statuses);
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

    public function test_database_rejects_mutation_or_deletion_of_imported_reconciliation_evidence(): void
    {
        [, $business, , $token] = $this->fixture();
        $response = $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/reconciliation/batches', [
                'source' => 'bank_statement', 'source_reference' => 'immutable-file-003', 'currency_code' => 'NGN',
                'entries' => [[
                    'source_transaction_id' => 'bank-tx-immutable', 'reference' => 'REF-IMMUTABLE',
                    'amount_minor' => 7100, 'occurred_at' => now()->toDateTimeString(),
                ]],
            ])->assertCreated();

        $entryId = $response->json('data.entries.0.id');
        try {
            DB::table('reconciliation_entries')->where('id', $entryId)->update(['amount_minor' => 1]);
            $this->fail('The database must reject edits to imported financial evidence.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }

        try {
            DB::table('reconciliation_entries')->where('id', $entryId)->delete();
            $this->fail('The database must reject deletion of imported financial evidence.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('cannot be deleted', strtolower($exception->getMessage()));
        }

        // Review state is allowed to change; the imported evidence is not.
        DB::table('reconciliation_entries')->where('id', $entryId)->update(['status' => 'conflict']);
        $this->assertDatabaseHas('reconciliation_entries', ['id' => $entryId, 'amount_minor' => 7100, 'status' => 'conflict']);
    }

    public function test_database_makes_reconciliation_decisions_append_only(): void
    {
        [, $business, , $token] = $this->fixture();
        $response = $this->withToken($token)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/reconciliation/batches', [
                'source' => 'bank_statement', 'source_reference' => 'decision-file-004', 'currency_code' => 'NGN',
                'entries' => [[
                    'source_transaction_id' => 'bank-tx-decision', 'reference' => 'REF-DECISION',
                    'amount_minor' => 8200,
                ]],
            ])->assertCreated();
        $entryId = $response->json('data.entries.0.id');
        $decisionId = DB::table('reconciliation_decisions')->insertGetId([
            'business_id' => $business->id, 'reconciliation_entry_id' => $entryId,
            'actor_user_id' => $business->created_by_user_id, 'decision' => 'conflict',
            'reason' => 'Statement evidence requires a separate manual review.',
            'evidence' => json_encode(['source' => 'test'], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);

        try {
            DB::table('reconciliation_decisions')->where('id', $decisionId)->update(['reason' => 'rewritten decision']);
            $this->fail('The database must reject edits to historical reconciliation decisions.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', strtolower($exception->getMessage()));
        }

        try {
            DB::table('reconciliation_decisions')->where('id', $decisionId)->delete();
            $this->fail('The database must reject deletion of historical reconciliation decisions.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', strtolower($exception->getMessage()));
        }
    }

}
