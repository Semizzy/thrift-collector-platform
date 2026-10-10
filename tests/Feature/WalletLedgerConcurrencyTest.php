<?php

namespace Tests\Feature;

use App\Models\Business;
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
}
