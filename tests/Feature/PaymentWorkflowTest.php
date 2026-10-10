<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed();
        $owner = User::query()->create(['name' => 'Payments Owner', 'email' => 'payments-owner@example.test', 'password' => 'strong-test-password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $reviewer = User::query()->create(['name' => 'Payments Reviewer', 'email' => 'payments-reviewer@example.test', 'password' => 'strong-test-password']);
        $reviewer->forceFill(['email_verified_at' => now()])->save();
        $business = Business::query()->create([
            'name' => 'Payments Business', 'slug' => 'payments-business', 'country_code' => 'NG',
            'status' => 'active', 'created_by_user_id' => $owner->id,
        ]);
        foreach ([$owner => 'business_owner', $reviewer => 'business_manager'] as $user => $role) {
            BusinessMembership::query()->create([
                'business_id' => $business->id, 'user_id' => $user->id, 'role' => $role,
                'status' => 'active', 'joined_at' => now(),
            ]);
        }
        $tokens = [];
        foreach ([$owner, $reviewer] as $user) {
            $plain = str_repeat($user->id === $owner->id ? 'o' : 'm', 64);
            PersonalAccessToken::query()->create([
                'user_id' => $user->id, 'name' => 'payments-test',
                'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDay(),
            ]);
            $tokens[$user->id] = $plain;
        }
        return [$owner, $reviewer, $business, $tokens];
    }

    public function test_live_gateways_are_explicitly_disabled_and_manual_deposit_is_idempotent(): void
    {
        [$owner, , $business, $tokens] = $this->fixture();
        $this->withToken($tokens[$owner->id])->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/v1/business/payments/gateways')->assertOk()
            ->assertJsonPath('data.0.provider', 'paystack')
            ->assertJsonPath('data.0.status', 'disabled')
            ->assertJsonPath('data.0.ready_for_live_execution', false);

        $payload = ['provider' => 'manual', 'amount_minor' => 12500, 'currency_code' => 'NGN', 'client_reference' => 'DEP-001'];
        $this->withToken($tokens[$owner->id])->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/payments/deposits', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'pending_review');
        $this->withToken($tokens[$owner->id])->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/payments/deposits', $payload)->assertCreated();
        $this->assertDatabaseCount('deposit_intents', 1);

        $this->withToken($tokens[$owner->id])->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/payments/deposits', [
                ...$payload, 'amount_minor' => 99900,
            ])->assertStatus(409);
        $this->assertDatabaseCount('deposit_intents', 1);
    }

    public function test_manual_payout_requires_independent_approval_and_third_party_evidence_submission(): void
    {
        [$owner, $reviewer, $business, $tokens] = $this->fixture();
        $headers = ['X-Business-Id' => (string) $business->id];
        $payload = [
            'provider' => 'manual', 'amount_minor' => 8000, 'currency_code' => 'NGN',
            'client_reference' => 'PAY-001', 'destination' => 'Bank account details for test only',
        ];
        $created = $this->withToken($tokens[$owner->id])->withHeaders($headers)
            ->postJson('/api/v1/business/payments/payouts', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'requested');
        $payoutId = $created->json('data.id');

        $this->withToken($tokens[$owner->id])->withHeaders($headers)
            ->postJson('/api/v1/business/payments/payouts/'.$payoutId.'/decision', [
                'decision' => 'approve', 'reason' => 'Approve the request for manual processing.',
            ])->assertForbidden();

        $this->withToken($tokens[$reviewer->id])->withHeaders($headers)
            ->postJson('/api/v1/business/payments/payouts/'.$payoutId.'/decision', [
                'decision' => 'approve', 'reason' => 'Approve the request for manual processing.',
            ])->assertOk()->assertJsonPath('data.status', 'approved_manual_execution_pending');

        $this->withToken($tokens[$reviewer->id])->withHeaders($headers)
            ->postJson('/api/v1/business/payments/payouts/'.$payoutId.'/manual-payment-evidence', [
                'reason' => 'Submitting the transfer receipt for a second review.',
                'transfer_reference' => 'BANK-TRANSFER-001',
            ])->assertForbidden();

        $this->assertDatabaseHas('payout_requests', ['id' => $payoutId, 'status' => 'approved_manual_execution_pending']);
        $this->assertDatabaseHas('payment_operation_events', ['operation_type' => 'payout', 'operation_id' => $payoutId, 'event' => 'payout_approved_manual_execution_pending']);
    }

    public function test_gateway_provider_deposit_is_rejected_while_live_execution_is_disabled(): void
    {
        [$owner, , $business, $tokens] = $this->fixture();
        $this->withToken($tokens[$owner->id])->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/payments/deposits', [
                'provider' => 'paystack', 'amount_minor' => 10000, 'currency_code' => 'NGN', 'client_reference' => 'PAYSTACK-001',
            ])->assertStatus(503);
        $this->assertDatabaseCount('deposit_intents', 0);
    }
}
