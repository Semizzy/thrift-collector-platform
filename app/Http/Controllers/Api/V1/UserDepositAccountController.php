<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayConfig;
use App\Models\PaymentOperationEvent;
use App\Models\UserDepositAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class UserDepositAccountController extends Controller
{
    private function credentials(int $businessId): ?array
    {
        $row = PaymentGatewayConfig::query()
            ->where('business_id', $businessId)
            ->where('provider', 'paystack')
            ->where('direction', 'deposit')
            ->first();
        if (! $row || ! is_array($row->public_config)) {
            return null;
        }
        $credentials = json_decode((string) $row->credentials_ciphertext, true);
        if (! is_array($credentials) || ! is_string($credentials['secret_key'] ?? null) || $credentials['secret_key'] === '') {
            return null;
        }
        return ['secret_key' => $credentials['secret_key'], 'public_config' => $row->public_config];
    }

    private function present(UserDepositAccount $account): array
    {
        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'bank_code' => $account->bank_code,
            'bank_name' => $account->bank_name,
            'account_number' => $account->account_number_ciphertext,
            'account_name' => $account->account_name_ciphertext,
            'currency_code' => $account->currency_code,
            'status' => $account->status,
            'assigned_at' => $account->assigned_at?->toISOString(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $accounts = UserDepositAccount::query()->where('business_id', $business->id)
            ->where('user_id', $request->user()->id)->latest()->get()
            ->map(fn (UserDepositAccount $account) => $this->present($account));
        return response()->json(['data' => $accounts, 'meta' => ['multiple_accounts_allowed' => true]]);
    }

    public function availableBanks(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $config = $this->credentials((int) $business->id);
        if (! $config) {
            return response()->json(['message' => 'Dedicated deposit accounts require administrator-configured Paystack credentials.'], 503);
        }
        try {
            $response = Http::acceptJson()->withToken($config['secret_key'])->timeout(8)
                ->get('https://api.paystack.co/dedicated_account/available_providers');
            if (! $response->successful() || ! $response->json('status')) {
                return response()->json(['message' => 'The provider bank directory is unavailable.'], 502);
            }
            $banks = collect((array) $response->json('data', []))
                ->filter(fn ($bank) => ! empty($bank['provider_slug']) && ! empty($bank['bank_name']))
                ->map(fn ($bank) => [
                    'code' => (string) $bank['provider_slug'],
                    'name' => (string) $bank['bank_name'],
                    'provider' => 'paystack',
                    'currency_code' => 'NGN',
                ])->values();
            return response()->json(['data' => $banks, 'meta' => ['provider' => 'paystack', 'currency_code' => 'NGN']]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'The provider bank directory is temporarily unavailable.'], 502);
        }
    }

    public function requestAccount(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'currency_code' => ['sometimes', Rule::in(['NGN'])],
            'client_reference' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'consent' => ['required', 'accepted'],
        ]);
        $existing = UserDepositAccount::query()->where('business_id', $business->id)
            ->where('client_reference', $data['client_reference'])->first();
        if ($existing) {
            if ($existing->user_id !== $request->user()->id || $existing->bank_code !== $data['bank_code']) {
                return response()->json(['message' => 'Idempotency reference was already used for a different account request.'], 409);
            }
            return response()->json(['data' => $this->present($existing), 'message' => 'Existing account request returned.'], 200);
        }

        $config = $this->credentials((int) $business->id);
        if (! $config || ($config['public_config']['virtual_accounts_enabled'] ?? false) !== true) {
            return response()->json([
                'message' => 'Dedicated-account provisioning is not enabled by an administrator. Provider credentials alone do not enable account creation.',
            ], 503);
        }

        $user = $request->user();
        $nameParts = preg_split('/\s+/', trim((string) $user->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($nameParts) < 2 || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Complete your full name and verified email before requesting a dedicated deposit account.'], 422);
        }
        $firstName = array_shift($nameParts);
        $lastName = array_pop($nameParts);
        $middleName = implode(' ', $nameParts);

        try {
            $customerResponse = Http::acceptJson()->withToken($config['secret_key'])->timeout(10)
                ->get('https://api.paystack.co/customer/'.rawurlencode($user->email));
            if ($customerResponse->successful() && $customerResponse->json('status') && is_array($customerResponse->json('data'))) {
                $customer = $customerResponse->json('data');
            } else {
                $customerPayload = ['email' => $user->email, 'first_name' => $firstName, 'last_name' => $lastName];
                if ($middleName !== '') $customerPayload['middle_name'] = $middleName;
                $customerResponse = Http::acceptJson()->withToken($config['secret_key'])->timeout(10)
                    ->post('https://api.paystack.co/customer', $customerPayload);
                if (! $customerResponse->successful() || ! $customerResponse->json('status') || ! is_array($customerResponse->json('data'))) {
                    return response()->json(['message' => 'The provider could not create or retrieve the customer record. No deposit account was assigned.'], 502);
                }
                $customer = $customerResponse->json('data');
            }
            $customerCode = $customer['customer_code'] ?? null;
            if (! is_string($customerCode) || $customerCode === '') {
                return response()->json(['message' => 'The provider did not return a customer reference. No deposit account was assigned.'], 502);
            }

            $assignment = Http::acceptJson()->withToken($config['secret_key'])->timeout(12)
                ->post('https://api.paystack.co/dedicated_account', [
                    'customer' => $customerCode,
                    'preferred_bank' => $data['bank_code'],
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ]);
            if (! $assignment->successful() || ! $assignment->json('status')) {
                return response()->json(['message' => 'The provider did not assign this bank account. Try another supported bank or contact support.'], 422);
            }
            $account = (array) $assignment->json('data', []);
            $accountNumber = (string) ($account['account_number'] ?? '');
            $accountName = (string) ($account['account_name'] ?? '');
            $bank = (array) ($account['bank'] ?? []);
            $providerAccountId = isset($account['id']) ? (string) $account['id'] : null;
            $bankName = (string) ($bank['name'] ?? $data['bank_code']);
            $assigned = $accountNumber !== '' && $accountName !== '' && (($account['assigned'] ?? true) === true);

            $record = UserDepositAccount::query()->create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'provider' => 'paystack',
                'provider_account_id' => $providerAccountId,
                'provider_customer_id' => $customerCode,
                'bank_code' => (string) ($bank['slug'] ?? $data['bank_code']),
                'bank_name' => $bankName,
                'account_number_ciphertext' => $assigned ? $accountNumber : null,
                'account_number_hash' => $assigned ? hash('sha256', $accountNumber) : null,
                'account_name_ciphertext' => $assigned ? $accountName : null,
                'currency_code' => (string) ($account['currency'] ?? $data['currency_code'] ?? 'NGN'),
                'status' => $assigned ? 'active' : 'provisioning',
                'client_reference' => $data['client_reference'],
                'assigned_at' => $assigned ? now() : null,
            ]);
            PaymentOperationEvent::query()->create([
                'business_id' => $business->id,
                'operation_type' => 'user_deposit_account',
                'operation_id' => $record->id,
                'actor_user_id' => $user->id,
                'event' => $assigned ? 'dedicated_account_assigned' : 'dedicated_account_assignment_pending',
                'reason' => 'User requested a dedicated deposit account with explicit consent. No wallet or ledger credit was posted.',
                'evidence' => ['provider' => 'paystack', 'bank_code' => $record->bank_code, 'provider_account_id' => $providerAccountId],
                'created_at' => now(),
            ]);
            return response()->json([
                'data' => $this->present($record),
                'message' => $assigned ? 'Dedicated deposit account assigned.' : 'Account assignment is pending provider confirmation.',
            ], $assigned ? 201 : 202);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Dedicated account provider is temporarily unavailable. No account was marked active.'], 502);
        }
    }
}
