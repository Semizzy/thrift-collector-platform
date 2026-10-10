<?php

namespace App\\Http\\Controllers\\Api\\V1;

use App\\Http\\Controllers\\Controller;
use App\\Models\\BankAccountVerification;
use App\\Models\\PaymentGatewayConfig;
use App\\Models\\PaymentOperationEvent;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\Http;

class BankAccountVerificationController extends Controller
{
    private function paystackSecret(int $businessId): ?string
    {
        $config = PaymentGatewayConfig::query()
            ->where('business_id', $businessId)
            ->where('provider', 'paystack')
            ->whereIn('direction', ['payout', 'deposit'])
            ->orderByRaw("CASE WHEN direction = 'payout' THEN 0 ELSE 1 END")
            ->get()
            ->first(fn ($row) => is_array(json_decode((string) $row->credentials_ciphertext, true))
                && ! empty(json_decode((string) $row->credentials_ciphertext, true)['secret_key']));

        if (! $config) {
            return null;
        }

        $credentials = json_decode((string) $config->credentials_ciphertext, true);
        return is_string($credentials['secret_key'] ?? null) ? $credentials['secret_key'] : null;
    }

    public function banks(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $secret = $this->paystackSecret((int) $business->id);
        if (! $secret) {
            return response()->json([
                'message' => 'Bank directory is unavailable until an administrator stores Paystack credentials. No bank list was fabricated.',
            ], 503);
        }

        try {
            $banks = Cache::remember('paystack-ng-banks-'.$business->id, now()->addHours(6), function () use ($secret): array {
                $all = [];
                $cursor = null;
                for ($page = 0; $page < 10; $page++) {
                    $query = ['country' => 'nigeria', 'perPage' => 100, 'use_cursor' => 'true'];
                    if ($cursor) {
                        $query['next'] = $cursor;
                    }
                    $response = Http::acceptJson()->withToken($secret)->timeout(8)
                        ->get('https://api.paystack.co/bank', $query);
                    if (! $response->successful() || ! $response->json('status')) {
                        throw new \\RuntimeException('Bank directory request failed.');
                    }
                    foreach ((array) $response->json('data', []) as $bank) {
                        if (! empty($bank['name']) && ! empty($bank['code'])) {
                            $all[(string) $bank['code']] = [
                                'name' => (string) $bank['name'],
                                'code' => (string) $bank['code'],
                                'country' => 'NG',
                                'supports_account_name_resolution' => true,
                            ];
                        }
                    }
                    $next = $response->json('meta.next');
                    if (! is_string($next) || $next === '' || $next === $cursor) {
                        break;
                    }
                    $cursor = $next;
                }
                return array_values($all);
            });
        } catch (\\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Bank directory provider is temporarily unavailable. Try again later.'], 502);
        }

        return response()->json([
            'data' => $banks,
            'meta' => ['country' => 'NG', 'source' => 'paystack', 'cached' => Cache::has('paystack-ng-banks-'.$business->id)],
        ]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
        ]);
        $secret = $this->paystackSecret((int) $business->id);
        if (! $secret) {
            return response()->json(['message' => 'Account-name verification is unavailable until an administrator stores Paystack credentials.'], 503);
        }

        try {
            $response = Http::acceptJson()->withToken($secret)->timeout(8)->get('https://api.paystack.co/bank/resolve', [
                'account_number' => $data['account_number'],
                'bank_code' => $data['bank_code'],
            ]);
        } catch (\\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Account-name verification provider is temporarily unavailable. No account was marked verified.'], 502);
        }

        $accountName = $response->json('data.account_name');
        $resolvedNumber = (string) $response->json('data.account_number', '');
        if (! $response->successful() || ! $response->json('status') || ! is_string($accountName) || trim($accountName) === '' || $resolvedNumber !== $data['account_number']) {
            return response()->json(['message' => 'The bank could not verify this account number. Check the selected bank and account number.'], 422);
        }

        $bank = Cache::get('paystack-ng-banks-'.$business->id, []);
        $bankRecord = collect($bank)->first(fn ($item) => (string) ($item['code'] ?? '') === $data['bank_code']);
        if (! $bankRecord) {
            return response()->json(['message' => 'Select a bank from the current supported bank directory and try again.'], 422);
        }

        $verification = BankAccountVerification::query()->create([
            'business_id' => $business->id,
            'user_id' => $request->user()->id,
            'verification_provider' => 'paystack',
            'bank_code' => $data['bank_code'],
            'bank_name' => $bankRecord['name'],
            'account_number_ciphertext' => $data['account_number'],
            'account_name_ciphertext' => trim($accountName),
            'status' => 'verified',
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);

        PaymentOperationEvent::query()->create([
            'business_id' => $business->id,
            'operation_type' => 'bank_account_verification',
            'operation_id' => $verification->id,
            'actor_user_id' => $request->user()->id,
            'event' => 'bank_account_name_resolved',
            'reason' => 'Account name resolved by the configured Paystack verification API; no payment or payout was initiated.',
            'evidence' => ['provider' => 'paystack', 'bank_code' => $data['bank_code'], 'account_number_last4' => substr($data['account_number'], -4)],
            'created_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'verification_id' => $verification->id,
                'bank_code' => $verification->bank_code,
                'bank_name' => $verification->bank_name,
                'account_number_masked' => '******'.substr($data['account_number'], -4),
                'account_name' => $verification->account_name_ciphertext,
                'verified' => true,
                'verified_by' => 'paystack',
                'expires_at' => $verification->expires_at->toISOString(),
            ],
            'message' => 'Account name verified. Review the returned name before submitting your payout.',
        ]);
    }
}
