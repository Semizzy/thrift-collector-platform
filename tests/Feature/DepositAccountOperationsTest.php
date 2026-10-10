<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\UserDepositAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DepositAccountOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stuck_assignment_is_diagnosable_and_can_be_escalated_without_financial_posting(): void
    {
        $this->seed();
        $owner = User::query()->create([
            'name' => 'Operations Owner', 'email' => 'operations-owner@example.test',
            'password' => 'strong-test-password', 'email_verified_at' => now(),
        ]);
        $business = Business::query()->create([
            'name' => 'Operations Business', 'slug' => 'operations-business',
            'country_code' => 'NG', 'status' => 'active', 'created_by_user_id' => $owner->id,
        ]);
        BusinessMembership::query()->create([
            'business_id' => $business->id, 'user_id' => $owner->id,
            'role' => 'business_owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $plain = str_repeat('q', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $owner->id, 'name' => 'operations-test',
            'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDay(),
        ]);
        $account = UserDepositAccount::query()->create([
            'business_id' => $business->id, 'user_id' => $owner->id, 'provider' => 'paystack',
            'provider_customer_id' => 'CUS-STUCK-001', 'bank_code' => 'wema-bank',
            'bank_name' => 'Wema Bank', 'currency_code' => 'NGN',
            'status' => 'provisioning', 'client_reference' => 'STUCK-001',
            'recovery_attempts' => 12, 'last_recovery_result' => 'still_pending',
        ]);
        $account->forceFill(['created_at' => now()->subHours(3)])->save();
        $headers = ['X-Business-Id' => (string) $business->id];

        $this->withToken($plain)->withHeaders($headers)
            ->getJson('/api/v1/business/payments/deposit-accounts/operations')
            ->assertOk()->assertJsonPath('data.0.id', $account->id)
            ->assertJsonPath('data.0.diagnostic_code', 'retry_limit_reached')
            ->assertJsonPath('meta.financial_posting_enabled', false);

        $this->withToken($plain)->withHeaders($headers)
            ->postJson('/api/v1/business/payments/deposit-accounts/'.$account->id.'/escalate', [
                'reason' => 'Provider has not assigned after all scheduled recovery attempts.',
            ])->assertCreated()->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('deposit_account_escalations', [
            'business_id' => $business->id, 'user_deposit_account_id' => $account->id,
            'requested_by_user_id' => $owner->id, 'status' => 'open',
        ]);
        $this->assertDatabaseCount('deposit_intents', 0);
        $this->assertDatabaseHas('payment_operation_events', [
            'operation_type' => 'user_deposit_account', 'operation_id' => $account->id,
            'event' => 'deposit_account_escalated',
        ]);
    }
}
