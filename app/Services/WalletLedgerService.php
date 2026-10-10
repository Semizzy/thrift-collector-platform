<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletLedgerService
{
    public function balance(int $businessId, int $userId, string $currency): int
    {
        $key = $this->walletKey($userId, $currency);
        return (int) (DB::table('wallet_accounts')->where('business_id', $businessId)
            ->where('account_key', $key)->value('balance_minor') ?? 0);
    }

    public function entries(int $businessId, int $userId, string $currency, int $limit = 50): array
    {
        $key = $this->walletKey($userId, $currency);
        $account = DB::table('wallet_accounts')->where('business_id', $businessId)
            ->where('account_key', $key)->first();
        if (! $account) return [];

        return DB::table('wallet_ledger_entries as e')
            ->join('wallet_ledger_transactions as t', 't.id', '=', 'e.wallet_ledger_transaction_id')
            ->where('t.business_id', $businessId)->where('e.wallet_account_id', $account->id)
            ->orderByDesc('e.id')->limit(max(1, min(100, $limit)))
            ->get(['e.id', 'e.direction', 'e.amount_minor', 'e.balance_after_minor', 'e.created_at',
                't.source_type', 't.source_id', 't.description', 't.idempotency_key'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function creditContribution(int $businessId, int $userId, string $currency, int $amount, int $contributionId, ?int $actorId): array
    {
        return $this->post($businessId, $currency, 'contribution_record', $contributionId,
            'contribution:'.$contributionId.':wallet-credit', 'Confirmed savings contribution credited to member wallet.',
            [
                ['key' => $this->systemKey('contribution-clearing', $currency), 'type' => 'contribution_clearing', 'user_id' => null, 'normal' => 'debit', 'direction' => 'debit', 'amount' => $amount],
                ['key' => $this->walletKey($userId, $currency), 'type' => 'member_wallet', 'user_id' => $userId, 'normal' => 'credit', 'direction' => 'credit', 'amount' => $amount],
            ], $actorId);
    }

    public function debitWithdrawal(int $businessId, int $userId, string $currency, int $amount, int $payoutId, ?int $actorId): array
    {
        return $this->post($businessId, $currency, 'payout_request', $payoutId,
            'payout:'.$payoutId.':wallet-debit', 'Wallet funds reserved for withdrawal request.',
            [
                ['key' => $this->walletKey($userId, $currency), 'type' => 'member_wallet', 'user_id' => $userId, 'normal' => 'credit', 'direction' => 'debit', 'amount' => $amount],
                ['key' => $this->systemKey('payout-payable', $currency), 'type' => 'payout_payable', 'user_id' => null, 'normal' => 'credit', 'direction' => 'credit', 'amount' => $amount],
            ], $actorId, $this->walletKey($userId, $currency));
    }

    public function refundWithdrawal(int $businessId, int $userId, string $currency, int $amount, int $payoutId, ?int $actorId, string $reason): array
    {
        return $this->post($businessId, $currency, 'payout_request', $payoutId,
            'payout:'.$payoutId.':wallet-refund', 'Withdrawal wallet refund: '.$reason,
            [
                ['key' => $this->systemKey('payout-payable', $currency), 'type' => 'payout_payable', 'user_id' => null, 'normal' => 'credit', 'direction' => 'debit', 'amount' => $amount],
                ['key' => $this->walletKey($userId, $currency), 'type' => 'member_wallet', 'user_id' => $userId, 'normal' => 'credit', 'direction' => 'credit', 'amount' => $amount],
            ], $actorId);
    }

    public function hasWithdrawalDebit(int $businessId, int $payoutId): bool
    {
        return DB::table('wallet_ledger_transactions')->where('business_id', $businessId)
            ->where('idempotency_key', 'payout:'.$payoutId.':wallet-debit')->exists();
    }

    public function settleWithdrawal(int $businessId, string $currency, int $amount, int $payoutId, ?int $actorId, string $reference): array
    {
        return $this->post($businessId, $currency, 'payout_request', $payoutId,
            'payout:'.$payoutId.':settlement', 'Withdrawal settled against independently reviewed bank evidence.',
            [
                ['key' => $this->systemKey('payout-payable', $currency), 'type' => 'payout_payable', 'user_id' => null, 'normal' => 'credit', 'direction' => 'debit', 'amount' => $amount],
                ['key' => $this->systemKey('bank-clearing', $currency), 'type' => 'bank_clearing', 'user_id' => null, 'normal' => 'debit', 'direction' => 'credit', 'amount' => $amount],
            ], $actorId, null, ['transfer_reference' => $reference]);
    }

    private function post(
        int $businessId,
        string $currency,
        string $sourceType,
        int $sourceId,
        string $idempotencyKey,
        string $description,
        array $lines,
        ?int $actorId,
        ?string $requiredWalletBalanceKey = null,
        array $metadata = [],
    ): array {
        if ($currency !== strtoupper($currency) || strlen($currency) !== 3 || array_filter($lines, fn ($line) => $line['amount'] < 1)) {
            throw new InvalidArgumentException('Ledger entries require a valid currency and positive amounts.');
        }
        $debits = array_sum(array_map(fn ($line) => $line['direction'] === 'debit' ? $line['amount'] : 0, $lines));
        $credits = array_sum(array_map(fn ($line) => $line['direction'] === 'credit' ? $line['amount'] : 0, $lines));
        if ($debits !== $credits || count($lines) < 2) {
            throw new InvalidArgumentException('Every wallet journal must contain equal debits and credits.');
        }

        try {
            return DB::transaction(function () use ($businessId, $currency, $sourceType, $sourceId, $idempotencyKey, $description, $lines, $actorId, $requiredWalletBalanceKey, $metadata): array {
            $keys = array_values(array_unique(array_column($lines, 'key')));
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                $line = collect($lines)->firstWhere('key', $key);
                DB::table('wallet_accounts')->insertOrIgnore([
                    'business_id' => $businessId, 'user_id' => $line['user_id'],
                    'account_key' => $key, 'account_type' => $line['type'],
                    'normal_balance' => $line['normal'], 'currency_code' => $currency,
                    'balance_minor' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $accounts = [];
            foreach ($keys as $key) {
                $accounts[$key] = DB::table('wallet_accounts')->where('business_id', $businessId)
                    ->where('account_key', $key)->lockForUpdate()->first();
            }

            $existing = DB::table('wallet_ledger_transactions')->where('business_id', $businessId)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (! $this->existingPostingMatches($existing, $businessId, $currency, $sourceType, $sourceId, $lines, $metadata)) {
                    throw new InvalidArgumentException('An idempotency key cannot be reused with different ledger details.');
                }
                return ['status' => 'already_posted', 'transaction_id' => $existing->id];
            }

            if ($requiredWalletBalanceKey !== null
                && (int) $accounts[$requiredWalletBalanceKey]->balance_minor < array_sum(array_map(
                    fn ($line) => $line['key'] === $requiredWalletBalanceKey && $line['direction'] === 'debit' ? $line['amount'] : 0,
                    $lines
                ))) {
                return ['status' => 'insufficient_balance', 'transaction_id' => null];
            }

            $transactionId = DB::table('wallet_ledger_transactions')->insertGetId([
                'business_id' => $businessId, 'currency_code' => $currency,
                'source_type' => $sourceType, 'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey, 'description' => $description,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_by_user_id' => $actorId, 'created_at' => now(),
            ]);

            foreach ($lines as $line) {
                $account = $accounts[$line['key']];
                $normalIncrease = $line['direction'] === $line['normal'];
                $delta = $normalIncrease ? $line['amount'] : -$line['amount'];
                $accountUpdate = DB::table('wallet_accounts')->where('id', $account->id);
                if ($line['type'] === 'member_wallet' && $delta < 0) {
                    // Keep the non-negative invariant in the UPDATE itself, not only in a prior read.
                    // This is a final atomic guard if two withdrawals race for the same available funds.
                    $accountUpdate->where('balance_minor', '>=', -$delta);
                }
                $updated = $accountUpdate->update([
                    'balance_minor' => DB::raw('balance_minor + ('.(int) $delta.')'),
                    'updated_at' => now(),
                ]);
                if ($updated !== 1 && $line['type'] === 'member_wallet' && $delta < 0) {
                    throw new InsufficientWalletBalance('Insufficient wallet balance for this withdrawal.');
                }
                $newBalance = (int) DB::table('wallet_accounts')->where('id', $account->id)->value('balance_minor');
                DB::table('wallet_ledger_entries')->insert([
                    'wallet_ledger_transaction_id' => $transactionId, 'wallet_account_id' => $account->id,
                    'direction' => $line['direction'], 'amount_minor' => $line['amount'],
                    'balance_after_minor' => $newBalance, 'created_at' => now(),
                ]);
                $accounts[$line['key']]->balance_minor = $newBalance;
            }

            return ['status' => 'posted', 'transaction_id' => $transactionId];
            }, 3);
        } catch (InsufficientWalletBalance) {
            return ['status' => 'insufficient_balance', 'transaction_id' => null];
        } catch (QueryException $exception) {
            // Concurrent requests can reach the unique idempotency constraint before observing
            // the winning transaction. Treat only an exact matching journal as a replay.
            $existing = DB::table('wallet_ledger_transactions')->where('business_id', $businessId)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing && $this->existingPostingMatches($existing, $businessId, $currency, $sourceType, $sourceId, $lines, $metadata)) {
                return ['status' => 'already_posted', 'transaction_id' => $existing->id];
            }
            throw $exception;
        }
    }

    private function existingPostingMatches(object $transaction, int $businessId, string $currency, string $sourceType, int $sourceId, array $lines, array $metadata): bool
    {
        if ((int) $transaction->business_id !== $businessId
            || $transaction->currency_code !== $currency
            || $transaction->source_type !== $sourceType
            || (int) $transaction->source_id !== $sourceId
            || (json_decode((string) $transaction->metadata, true) ?? []) !== $metadata) {
            return false;
        }

        $expected = array_map(static fn (array $line): array => [
            'account_key' => $line['key'],
            'direction' => $line['direction'],
            'amount_minor' => (int) $line['amount'],
        ], $lines);
        $actual = DB::table('wallet_ledger_entries as e')
            ->join('wallet_accounts as a', 'a.id', '=', 'e.wallet_account_id')
            ->where('e.wallet_ledger_transaction_id', $transaction->id)
            ->get(['a.account_key', 'e.direction', 'e.amount_minor'])
            ->map(static fn ($row): array => [
                'account_key' => $row->account_key,
                'direction' => $row->direction,
                'amount_minor' => (int) $row->amount_minor,
            ])->all();

        $sort = static function (array &$items): void {
            usort($items, static fn (array $a, array $b): int =>
                [$a['account_key'], $a['direction'], $a['amount_minor']]
                <=> [$b['account_key'], $b['direction'], $b['amount_minor']]
            );
        };
        $sort($expected);
        $sort($actual);

        return $expected === $actual;
    }

    private function walletKey(int $userId, string $currency): string
    {
        return 'wallet:'.$userId.':'.$currency;
    }

    private function systemKey(string $type, string $currency): string
    {
        return $type.':'.$currency;
    }
}
