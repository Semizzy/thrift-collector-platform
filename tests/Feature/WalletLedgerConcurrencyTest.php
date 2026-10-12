<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ContributionRecord;
use App\Models\ThriftGroup;
use App\Models\User;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WalletLedgerConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private int $businessId;
    private int $userId;
    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            return;
        }

        $this->seed();
        $owner = User::query()->create([
            'name' => 'Concurrency Owner', 'email' => 'concurrency-owner@example.test',
            'password' => 'strong-test-password',
        ]);
        $actor = User::query()->create([
            'name' => 'Concurrency Reviewer', 'email' => 'concurrency-reviewer@example.test',
            'password' => 'strong-test-password',
        ]);
        $business = Business::query()->create([
            'name' => 'Concurrency Business', 'slug' => 'concurrency-business',
            'country_code' => 'NG', 'status' => 'active', 'created_by_user_id' => $owner->id,
        ]);

        $this->businessId = (int) $business->id;
        $this->userId = (int) $owner->id;
        $this->actorId = (int) $actor->id;

        app(WalletLedgerService::class)->creditContribution(
            $this->businessId, $this->userId, 'NGN', 10000, 990001, $this->actorId
        );
    }

    public function test_two_independent_mysql_connections_cannot_overspend_the_same_wallet(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This integration test requires MySQL and pcntl process support.');
        }

        $results = $this->runConcurrentDebits([990101, 990102], [7000, 7000]);
        $statuses = array_column($results, 'status');
        sort($statuses);

        self::assertSame(['insufficient_balance', 'posted'], $statuses);
        self::assertSame(3000, app(WalletLedgerService::class)->balance($this->businessId, $this->userId, 'NGN'));
        self::assertGreaterThanOrEqual(0, (int) DB::table('wallet_accounts')
            ->where('business_id', $this->businessId)->where('account_key', 'wallet:'.$this->userId.':NGN')
            ->value('balance_minor'));

        $this->assertEveryJournalBalances();
    }

    public function test_two_independent_mysql_connections_replaying_same_idempotency_key_debit_once(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This integration test requires MySQL and pcntl process support.');
        }

        $results = $this->runConcurrentDebits([990201, 990201], [7000, 7000]);
        $statuses = array_column($results, 'status');
        sort($statuses);

        self::assertSame(['already_posted', 'posted'], $statuses);
        self::assertSame(3000, app(WalletLedgerService::class)->balance($this->businessId, $this->userId, 'NGN'));
        self::assertSame(1, DB::table('wallet_ledger_transactions')
            ->where('business_id', $this->businessId)->where('idempotency_key', 'payout:990201:wallet-debit')->count());
        $walletAccountId = DB::table('wallet_accounts')->where('business_id', $this->businessId)
            ->where('account_key', 'wallet:'.$this->userId.':NGN')->value('id');
        self::assertSame(1, DB::table('wallet_ledger_entries as e')
            ->join('wallet_ledger_transactions as t', 't.id', '=', 'e.wallet_ledger_transaction_id')
            ->where('t.business_id', $this->businessId)->where('t.idempotency_key', 'payout:990201:wallet-debit')
            ->where('e.direction', 'debit')->where('e.wallet_account_id', $walletAccountId)->count());

        $this->assertEveryJournalBalances();
    }

    public function test_two_independent_mysql_connections_post_only_one_contribution_reversal(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This integration test requires MySQL and pcntl process support.');
        }

        $group = ThriftGroup::query()->create([
            'business_id' => $this->businessId, 'name' => 'Reversal Race Group',
            'currency_code' => 'NGN', 'status' => 'active', 'created_by_user_id' => $this->userId,
        ]);
        $record = ContributionRecord::query()->create([
            'thrift_group_id' => $group->id, 'user_id' => $this->userId,
            'recorded_by_user_id' => $this->actorId, 'amount_minor' => 2500,
            'currency_code' => 'NGN', 'status' => 'confirmed', 'collection_method' => 'cash',
            'confirmed_by_user_id' => $this->actorId, 'confirmed_at' => now(),
        ]);
        app(WalletLedgerService::class)->creditContribution(
            $this->businessId, $this->userId, 'NGN', 2500, (int) $record->id, $this->actorId,
        );

        $results = $this->runConcurrentReversals((int) $record->id, 990301);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame(['already_posted', 'posted'], $statuses);
        self::assertSame(10000, app(WalletLedgerService::class)->balance($this->businessId, $this->userId, 'NGN'));
        self::assertSame(1, DB::table('wallet_ledger_transactions')->where('business_id', $this->businessId)
            ->where('idempotency_key', 'contribution:'.$record->id.':wallet-reversal')->count());
        $this->assertEveryJournalBalances();
    }

    private function runConcurrentReversals(int $contributionId, int $adjustmentId): array
    {
        $prefix = sys_get_temp_dir().'/wallet-reversal-race-'.bin2hex(random_bytes(8));
        $gate = $prefix.'-go';
        $children = [];
        foreach ([0, 1] as $index) {
            $ready = $prefix.'-ready-'.$index;
            $result = $prefix.'-result-'.$index;
            $pid = pcntl_fork();
            if ($pid === -1) throw new \RuntimeException('Could not fork a reversal worker.');
            if ($pid === 0) {
                try {
                    file_put_contents($ready, 'ready', LOCK_EX);
                    $deadline = microtime(true) + 15;
                    while (! is_file($gate) && microtime(true) < $deadline) usleep(1000);
                    if (! is_file($gate)) throw new \RuntimeException('Timed out waiting for reversal start gate.');
                    DB::purge();
                    DB::reconnect();
                    $posting = app(WalletLedgerService::class)->reverseContribution(
                        $this->businessId, $contributionId, $adjustmentId, $this->actorId,
                    );
                    file_put_contents($result, json_encode($posting, JSON_THROW_ON_ERROR), LOCK_EX);
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($result, json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR), LOCK_EX);
                    exit(1);
                }
            }
            $children[] = ['pid' => $pid, 'ready' => $ready, 'result' => $result];
        }

        $deadline = microtime(true) + 15;
        foreach ($children as $child) {
            while (! is_file($child['ready']) && microtime(true) < $deadline) usleep(1000);
            if (! is_file($child['ready'])) {
                @touch($gate);
                throw new \RuntimeException('Reversal worker did not reach the start barrier.');
            }
        }
        touch($gate);
        $results = [];
        foreach ($children as $child) {
            pcntl_waitpid($child['pid'], $status);
            $raw = is_file($child['result']) ? file_get_contents($child['result']) : false;
            if (! is_string($raw)) throw new \RuntimeException('Reversal worker did not write a result.');
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (isset($decoded['error'])) throw new \RuntimeException('Reversal worker failed: '.$decoded['error']);
            $results[] = $decoded;
            @unlink($child['ready']);
            @unlink($child['result']);
        }
        @unlink($gate);
        return $results;
    }

    private function runConcurrentDebits(array $payoutIds, array $amounts): array
    {
        $prefix = sys_get_temp_dir().'/wallet-race-'.bin2hex(random_bytes(8));
        $gate = $prefix.'-go';
        $children = [];

        foreach ($payoutIds as $index => $payoutId) {
            $ready = $prefix.'-ready-'.$index;
            $result = $prefix.'-result-'.$index;
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Could not fork a wallet concurrency worker.');
            }
            if ($pid === 0) {
                try {
                    file_put_contents($ready, 'ready', LOCK_EX);
                    $deadline = microtime(true) + 15;
                    while (! is_file($gate) && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    if (! is_file($gate)) {
                        throw new \RuntimeException('Timed out waiting for concurrent start gate.');
                    }

                    DB::purge();
                    DB::reconnect();
                    $posting = app(WalletLedgerService::class)->debitWithdrawal(
                        $this->businessId, $this->userId, 'NGN', $amounts[$index], $payoutId, $this->actorId
                    );
                    file_put_contents($result, json_encode($posting, JSON_THROW_ON_ERROR), LOCK_EX);
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($result, json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR), LOCK_EX);
                    exit(1);
                }
            }
            $children[] = ['pid' => $pid, 'ready' => $ready, 'result' => $result];
        }

        $deadline = microtime(true) + 15;
        foreach ($children as $child) {
            while (! is_file($child['ready']) && microtime(true) < $deadline) {
                usleep(1000);
            }
            if (! is_file($child['ready'])) {
                @touch($gate);
                throw new \RuntimeException('Wallet concurrency worker did not reach the start barrier.');
            }
        }
        touch($gate);

        $results = [];
        foreach ($children as $child) {
            pcntl_waitpid($child['pid'], $status);
            $raw = is_file($child['result']) ? file_get_contents($child['result']) : false;
            if (! is_string($raw)) {
                throw new \RuntimeException('Wallet concurrency worker did not write a result.');
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (isset($decoded['error'])) {
                throw new \RuntimeException('Wallet concurrency worker failed: '.$decoded['error']);
            }
            $results[] = $decoded;
            @unlink($child['ready']);
            @unlink($child['result']);
        }
        @unlink($gate);

        return $results;
    }

    private function assertEveryJournalBalances(): void
    {
        $journals = DB::table('wallet_ledger_transactions')->where('business_id', $this->businessId)->pluck('id');
        foreach ($journals as $journalId) {
            $debits = (int) DB::table('wallet_ledger_entries')->where('wallet_ledger_transaction_id', $journalId)
                ->where('direction', 'debit')->sum('amount_minor');
            $credits = (int) DB::table('wallet_ledger_entries')->where('wallet_ledger_transaction_id', $journalId)
                ->where('direction', 'credit')->sum('amount_minor');
            self::assertSame($debits, $credits, 'Journal '.$journalId.' must balance under contention.');
        }
    }

    public function test_interrupted_journal_entry_insert_rolls_back_transaction_and_balances(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Failure injection for this ledger path is validated on MySQL 8.');
        }

        $beforeTransactions = DB::table('wallet_ledger_transactions')->where('business_id', $this->businessId)->count();
        $beforeEntries = DB::table('wallet_ledger_entries as e')
            ->join('wallet_ledger_transactions as t', 't.id', '=', 'e.wallet_ledger_transaction_id')
            ->where('t.business_id', $this->businessId)->count();
        $beforeBalance = app(WalletLedgerService::class)->balance($this->businessId, $this->userId, 'NGN');
        $injected = false;

        DB::listen(static function ($query) use (&$injected): void {
            if (! $injected && str_starts_with(strtolower(ltrim($query->sql)), 'insert into `wallet_ledger_entries`')) {
                $injected = true;
                throw new RuntimeException('Injected failure after a ledger entry insert.');
            }
        });

        try {
            app(WalletLedgerService::class)->creditContribution(
                $this->businessId, $this->userId, 'NGN', 1234, 990401, $this->actorId
            );
            self::fail('Injected database-path failure should escape the ledger operation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Injected failure after a ledger entry insert.', $exception->getMessage());
        }

        self::assertTrue($injected, 'The failure injector must hit the ledger entry write.');
        self::assertSame($beforeTransactions, DB::table('wallet_ledger_transactions')->where('business_id', $this->businessId)->count());
        self::assertSame($beforeEntries, DB::table('wallet_ledger_entries as e')
            ->join('wallet_ledger_transactions as t', 't.id', '=', 'e.wallet_ledger_transaction_id')
            ->where('t.business_id', $this->businessId)->count());
        self::assertSame($beforeBalance, app(WalletLedgerService::class)->balance($this->businessId, $this->userId, 'NGN'));
    }

    public function test_wallet_balance_and_journal_are_isolated_by_business_and_currency(): void
    {
        $otherBusiness = Business::query()->create([
            'name' => 'Other Isolation Business', 'slug' => 'other-isolation-business',
            'country_code' => 'NG', 'status' => 'active', 'created_by_user_id' => $this->userId,
        ]);
        $ledger = app(WalletLedgerService::class);

        self::assertSame(0, $ledger->balance((int) $otherBusiness->id, $this->userId, 'NGN'));
        $otherCurrency = $ledger->creditContribution(
            $this->businessId, $this->userId, 'USD', 1500, 990402, $this->actorId
        );
        self::assertSame('posted', $otherCurrency['status']);
        self::assertSame(10000, $ledger->balance($this->businessId, $this->userId, 'NGN'));
        self::assertSame(1500, $ledger->balance($this->businessId, $this->userId, 'USD'));
        self::assertSame(0, $ledger->balance((int) $otherBusiness->id, $this->userId, 'USD'));

        self::assertDatabaseHas('wallet_ledger_transactions', [
            'business_id' => $this->businessId, 'currency_code' => 'USD',
            'idempotency_key' => 'contribution:990402:wallet-credit',
        ]);
        self::assertSame(0, DB::table('wallet_ledger_transactions')
            ->where('business_id', $otherBusiness->id)->count());
        $this->assertEveryJournalBalances();
    }

    public function test_insufficient_withdrawal_does_not_create_partial_journal(): void
    {
        $beforeTransactions = DB::table('wallet_ledger_transactions')->where('business_id', $this->businessId)->count();
        $beforeEntries = DB::table('wallet_ledger_entries as e')
            ->join('wallet_ledger_transactions as t', 't.id', '=', 'e.wallet_ledger_transaction_id')
            ->where('t.business_id', $this->businessId)->count();

        $result = app(WalletLedgerService::class)->debitWithdrawal(
            $this->businessId, $this->userId, 'NGN', 10001, 990403, $this->actorId
        );

        self::assertSame('insufficient_balance', $result['status']);
        self::assertNull($result['transaction_id']);
        self::assertSame(10000, app(WalletLedgerService::class)->balance($this->businessId, $this->userId, 'NGN'));
        self::assertSame($beforeTransactions, DB::table('wallet_ledger_transactions')->where('business_id', $this->businessId)->count());
        self::assertSame($beforeEntries, DB::table('wallet_ledger_entries as e')
            ->join('wallet_ledger_transactions as t', 't.id', '=', 'e.wallet_ledger_transaction_id')
            ->where('t.business_id', $this->businessId)->count());
    }

}
