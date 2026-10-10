<?php

namespace App\Console\Commands;

use App\Models\PaymentGatewayConfig;
use App\Models\PaymentOperationEvent;
use App\Models\UserDepositAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class RecoverPendingDepositAccounts extends Command
{
    protected $signature = 'payments:recover-deposit-accounts {--limit=50 : Maximum pending accounts to check}';

    protected $description = 'Requery Paystack for pending dedicated-account assignments without creating duplicate assignments';

    public function handle(): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $cutoff = now()->subMinutes(10);
        $accounts = UserDepositAccount::query()
            ->where('provider', 'paystack')
            ->where('status', 'provisioning')
            ->where('created_at', '<=', $cutoff)
            ->where('recovery_attempts', '<', 12)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_recovery_at')->orWhere('last_recovery_at', '<=', $cutoff);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        $assigned = 0;
        $unresolved = 0;

        foreach ($accounts as $account) {
            $config = PaymentGatewayConfig::query()
                ->where('business_id', $account->business_id)
                ->where('provider', 'paystack')
                ->where('direction', 'deposit')
                ->first();
            $credentials = $config ? json_decode((string) $config->credentials_ciphertext, true) : null;
            $secret = is_array($credentials) ? ($credentials['secret_key'] ?? null) : null;
            if (! is_string($secret) || $secret === '') {
                $this->recordAttempt($account, 'credentials_unavailable');
                $unresolved++;
                continue;
            }

            try {
                $providerAccount = null;
                if ($account->provider_account_id !== null && ctype_digit((string) $account->provider_account_id)) {
                    $response = Http::acceptJson()->withToken($secret)->timeout(8)
                        ->get('https://api.paystack.co/dedicated_account/'.rawurlencode((string) $account->provider_account_id));
                    if ($response->successful() && $response->json('status') && is_array($response->json('data'))) {
                        $providerAccount = $response->json('data');
                    }
                } elseif ($account->provider_customer_id) {
                    $response = Http::acceptJson()->withToken($secret)->timeout(8)
                        ->get('https://api.paystack.co/dedicated_account', [
                            'customer' => $account->provider_customer_id,
                            'perPage' => 50,
                        ]);
                    if ($response->successful() && $response->json('status')) {
                        $items = collect((array) $response->json('data', []));
                        $providerAccount = $items->first(function ($item) use ($account): bool {
                            $bank = (array) ($item['bank'] ?? []);
                            $slug = (string) ($bank['slug'] ?? '');
                            $name = (string) ($bank['name'] ?? '');
                            return ($slug !== '' && $slug === $account->bank_code)
                                || ($slug === '' && $name !== '' && strcasecmp($name, $account->bank_name) === 0);
                        });
                    }
                }

                if (is_array($providerAccount)
                    && ! empty($providerAccount['assigned'])
                    && ! empty($providerAccount['account_number'])
                    && ! empty($providerAccount['account_name'])) {
                    $bank = (array) ($providerAccount['bank'] ?? []);
                    DB::transaction(function () use ($account, $providerAccount, $bank): void {
                        $locked = UserDepositAccount::query()->whereKey($account->id)->lockForUpdate()->first();
                        if (! $locked || $locked->status !== 'provisioning') {
                            return;
                        }
                        $locked->forceFill([
                            'provider_account_id' => isset($providerAccount['id']) ? (string) $providerAccount['id'] : $locked->provider_account_id,
                            'account_number_ciphertext' => (string) $providerAccount['account_number'],
                            'account_number_hash' => hash('sha256', (string) $providerAccount['account_number']),
                            'account_name_ciphertext' => (string) $providerAccount['account_name'],
                            'bank_code' => (string) ($bank['slug'] ?? $locked->bank_code),
                            'bank_name' => (string) ($bank['name'] ?? $locked->bank_name),
                            'currency_code' => (string) ($providerAccount['currency'] ?? $locked->currency_code),
                            'status' => 'active',
                            'assigned_at' => now(),
                            'last_recovery_at' => now(),
                            'recovery_attempts' => $locked->recovery_attempts + 1,
                            'last_recovery_result' => 'assigned',
                        ])->save();
                        PaymentOperationEvent::query()->create([
                            'business_id' => $locked->business_id,
                            'operation_type' => 'user_deposit_account',
                            'operation_id' => $locked->id,
                            'actor_user_id' => null,
                            'event' => 'dva_assignment_recovered',
                            'reason' => 'Scheduled provider requery confirmed the existing account assignment. No new assignment was requested and no balance or ledger credit was posted.',
                            'evidence' => ['provider' => 'paystack', 'provider_account_id' => $locked->provider_account_id, 'bank_code' => $locked->bank_code],
                            'created_at' => now(),
                        ]);
                    });
                    $assigned++;
                } else {
                    $this->recordAttempt($account, 'still_pending');
                    $unresolved++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->recordAttempt($account, 'provider_error');
                $unresolved++;
            }
            $checked++;
        }

        $this->info("Checked {$checked} pending account(s); recovered {$assigned}; still pending or unavailable {$unresolved}.");
        return self::SUCCESS;
    }

    private function recordAttempt(UserDepositAccount $account, string $result): void
    {
        UserDepositAccount::query()->whereKey($account->id)->where('status', 'provisioning')->update([
            'last_recovery_at' => now(),
            'recovery_attempts' => DB::raw('recovery_attempts + 1'),
            'last_recovery_result' => $result,
            'updated_at' => now(),
        ]);
    }
}
