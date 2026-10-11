<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class TransactionPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_request_is_blocked_until_a_transaction_pin_is_configured(): void
    {
        $this->seed();
        $owner = User::query()->create([
            'name' => 'PIN Owner', 'email' => 'pin-owner@example.test',
            'password' => 'strong-test-password',
        ]);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $business = Business::query()->create([
            'name' => 'PIN Business', 'slug' => 'pin-business', 'country_code' => 'NG',
            'status' => 'active', 'created_by_user_id' => $owner->id,
        ]);
        BusinessMembership::query()->create([
            'business_id' => $business->id, 'user_id' => $owner->id,
            'role' => 'business_owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $plain = str_repeat('p', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $owner->id, 'name' => 'pin-test',
            'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDay(),
        ]);

        $this->withToken($plain)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/payments/deposits', [
                'provider' => 'manual', 'amount_minor' => 1000, 'currency_code' => 'NGN',
                'client_reference' => 'PIN-REQUIRED-001', 'purpose' => 'Monthly thrift contribution',
                'transaction_pin' => '2468',
            ])->assertStatus(428)->assertJsonPath('code', 'transaction_pin_required');

        $this->assertDatabaseCount('deposit_intents', 0);
    }
    public function test_three_incorrect_transaction_pins_lock_transactions_for_fifteen_minutes(): void
    {
        $this->seed();
        $owner = User::query()->create([
            'name' => 'PIN Lock Owner', 'email' => 'pin-lock-owner@example.test',
            'password' => 'strong-test-password',
        ]);
        $owner->forceFill([
            'email_verified_at' => now(),
            'transaction_pin_hash' => Hash::make('2468'),
        ])->save();
        $business = Business::query()->create([
            'name' => 'PIN Lock Business', 'slug' => 'pin-lock-business', 'country_code' => 'NG',
            'status' => 'active', 'created_by_user_id' => $owner->id,
        ]);
        BusinessMembership::query()->create([
            'business_id' => $business->id, 'user_id' => $owner->id,
            'role' => 'business_owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $plain = str_repeat('q', 64);
        PersonalAccessToken::query()->create([
            'user_id' => $owner->id, 'name' => 'pin-lock-test',
            'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDay(),
        ]);
        $payload = [
            'provider' => 'manual', 'amount_minor' => 1000, 'currency_code' => 'NGN',
            'client_reference' => 'PIN-LOCK-001', 'purpose' => 'Test transaction PIN lockout',
            'transaction_pin' => '9999',
        ];
        $request = fn (array $data) => $this->withToken($plain)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/v1/business/payments/deposits', $data);

        $request($payload)->assertUnprocessable();
        $request($payload)->assertUnprocessable();
        $request($payload)->assertStatus(429);
        $owner->refresh();
        self::assertSame(3, (int) $owner->transaction_pin_failed_attempts);
        self::assertTrue($owner->transaction_pin_locked_until->isFuture());

        $request([...$payload, 'transaction_pin' => '2468'])->assertStatus(429);
        $this->assertDatabaseCount('deposit_intents', 0);

        $this->travel(16)->minutes();
        $request([...$payload, 'transaction_pin' => '2468'])->assertCreated();
        $owner->refresh();
        self::assertSame(0, (int) $owner->transaction_pin_failed_attempts);
        self::assertNull($owner->transaction_pin_locked_until);
    }
}
