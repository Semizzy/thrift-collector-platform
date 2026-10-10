<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallet_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('account_key', 120);
            $table->string('account_type', 32);
            $table->string('normal_balance', 8);
            $table->char('currency_code', 3);
            $table->bigInteger('balance_minor')->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'account_key'], 'wallet_accounts_business_key_unique');
            $table->index(['business_id', 'user_id', 'currency_code', 'account_type'], 'wallet_accounts_owner_idx');
        });

        Schema::create('wallet_ledger_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('idempotency_key', 190);
            $table->string('description', 255);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['business_id', 'idempotency_key'], 'wallet_ledger_idempotency_unique');
            $table->index(['business_id', 'source_type', 'source_id'], 'wallet_ledger_source_idx');
        });

        Schema::create('wallet_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_ledger_transaction_id')->constrained('wallet_ledger_transactions')->restrictOnDelete();
            $table->foreignId('wallet_account_id')->constrained('wallet_accounts')->restrictOnDelete();
            $table->string('direction', 6);
            $table->unsignedBigInteger('amount_minor');
            $table->bigInteger('balance_after_minor');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['wallet_account_id', 'created_at', 'id'], 'wallet_ledger_account_timeline_idx');
        });

        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->string('status', 40)->default('requested')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->string('status', 24)->default('requested')->change();
        });
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallet_ledger_transactions');
        Schema::dropIfExists('wallet_accounts');
    }
};
