<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BankAccountVerification;
use App\Models\UserDepositAccount;
use App\Models\PaymentGatewayConfig;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        foreach ([[$owner, 'business_owner'], [$reviewer, 'business_manager']] as [$user, $role]) {
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
            'client_reference' => 'PAY-001', 'account_verification_id' => BankAccountVerification::query()->create([
                'business_id' => $business->id, 'user_id' => $owner->id, 'verification_provider' => 'paystack',
                'bank_code' => '044', 'bank_name' => 'Access Bank', 'account_number_ciphertext' => '0123456789',
                'account_name_ciphertext' => 'TEST ACCOUNT HOLDER', 'status' => 'verified',
                'verified_at' => now(), 'expires_at' => now()->addMinutes(10),
            ])->id,
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



    public function test_user_can_have_multiple_dedicated_deposit_accounts_from_different_banks(): void
    {
        [$owner, , $business, $tokens] = $this->fixture();
        foreach ([
            ['bank_code' => 'wema-bank', 'bank_name' => 'Wema Bank', 'account_number' => '0123456789', 'provider_id' => 'dva-1001'],
            ['bank_code' => 'titan-paystack', 'bank_name' => 'Titan Paystack', 'account_number' => '9876543210', 'provider_id' => 'dva-1002'],
        ] as $index => $bank) {
            UserDepositAccount::query()->create([
                'business_id' => $business->id, 'user_id' => $owner->id, 'provider' => 'paystack',
                'provider_account_id' => $bank['provider_id'], 'provider_customer_id' => 'CUS-TEST',
                'bank_code' => $bank['bank_code'], 'bank_name' => $bank['bank_name'],
                'account_number_ciphertext' => $bank['account_number'],
                'account_number_hash' => hash('sha256', $bank['account_number']),
                'account_name_ciphertext' => 'TEST USER NAME', 'currency_code' => 'NGN',
                'status' => 'active', 'client_reference' => 'DVA-TEST-'.($index + 1),
                'assigned_at' => now(),
            ]);
        }

        $this->withToken($tokens[$owner->id])->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/v1/business/payments/deposit-accounts')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.multiple_accounts_allowed', true)
            ->assertJsonFragment(['bank_name' => 'Wema Bank'])
            ->assertJsonFragment(['bank_name' => 'Titan Paystack']);
    }

    public function test_dedicated_account_creation_stays_disabled_without_explicit_admin_enablement(): void
    {
        [$owner, , $business, $tokens] = $this->fixture();
        PaymentGatewayConfig::query()->create([
            'business_id' => $business->id, 'provider' => 'paystack', 'direction' => 'deposit',
            'status' => 'disabled', 'public_config' => ['environment' => 'test'],
            'credentials_ciphertext' => json_encode(['secret_key' => 'not-a-real-secret'], JSON_THROW_ON_ERROR),
            'updated_by_user_id' => $owner->id,
        ]);

        $this->withToken($tokens[$owner->id])->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/payments/deposit-accounts', [
                'bank_code' => 'wema-bank', 'currency_code' => 'NGN',
                'client_reference' => 'DVA-REQUEST-001', 'consent' => true,
            ])->assertStatus(503);
        $this->assertDatabaseCount('user_deposit_accounts', 0);
    }

    public function test_account_name_resolution_returns_provider_verified_name_and_stores_sensitive_values_encrypted(): void
    {
        [$owner, , $business, $tokens] = $this->fixture();
        PaymentGatewayConfig::query()->create([
            'business_id' => $business->id, 'provider' => 'paystack', 'direction' => 'payout',
            'status' => 'disabled', 'public_config' => ['environment' => 'sandbox'],
            'credentials_ciphertext' => json_encode(['secret_key' => 'test-credential-value'], JSON_THROW_ON_ERROR),
            'updated_by_user_id' => $owner->id,
        ]);
        Cache::put('paystack-ng-banks-'.$business->id, [
            ['name' => 'Access Bank', 'code' => '044', 'country' => 'NG', 'supports_account_name_resolution' => true],
        ], now()->addHour());
        Http::fake([
            'https://api.paystack.co/bank/resolve*' => Http::response([
                'status' => true, 'message' => 'Account number resolved',
                'data' => ['account_number' => '0123456789', 'account_name' => 'TEST ACCOUNT HOLDER'],
            ], 200),
        ]);

        $response = $this->withToken($tokens[$owner->id])->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/payments/accounts/resolve', [
                'bank_code' => '044', 'account_number' => '0123456789',
            ])->assertOk()->assertJsonPath('data.account_name', 'TEST ACCOUNT HOLDER')
                ->assertJsonPath('data.verified', true)
                ->assertJsonPath('data.account_number_masked', '******6789');

        $verificationId = $response->json('data.verification_id');
        $this->assertDatabaseHas('bank_account_verifications', ['id' => $verificationId, 'status' => 'verified']);
        $stored = \Illuminate\Support\Facades\DB::table('bank_account_verifications')->where('id', $verificationId)->value('account_number_ciphertext');
        $this->assertStringNotContainsString('0123456789', $stored);
    }

    public function test_gateway_credentials_are_encrypted_and_configuration_cannot_enable_provider(): void
    {
        [$owner, , $business, $tokens] = $this->fixture();
        $this->withToken($tokens[$owner->id])->withHeader('X-Business-Id', (string) $business->id)
            ->putJson('/api/v1/business/payments/gateways/paystack/deposit', [
                'public_config' => ['environment' => 'sandbox'],
                'credentials' => ['secret_key' => 'sk_test_not_real', 'webhook_secret' => 'whsec_test_not_real'],
            ])->assertOk()->assertJsonPath('data.status', 'disabled')
                ->assertJsonMissing(['secret_key' => 'sk_test_not_real']);
        $this->assertDatabaseHas('payment_gateway_configs', [
            'business_id' => $business->id, 'provider' => 'paystack', 'direction' => 'deposit', 'status' => 'disabled',
        ]);
        $stored = \Illuminate\Support\Facades\DB::table('payment_gateway_configs')->where('business_id', $business->id)->value('credentials_ciphertext');
        $this->assertNotSame(json_encode(['secret_key' => 'sk_test_not_real', 'webhook_secret' => 'whsec_test_not_real']), $stored);
        $this->assertStringNotContainsString('sk_test_not_real', $stored);
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
