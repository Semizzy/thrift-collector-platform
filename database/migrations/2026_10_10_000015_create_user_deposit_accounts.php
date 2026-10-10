<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_deposit_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_account_id', 120)->nullable();
            $table->string('provider_customer_id', 120)->nullable();
            $table->string('bank_code', 80);
            $table->string('bank_name', 160);
            $table->text('account_number_ciphertext')->nullable();
            $table->char('account_number_hash', 64)->nullable();
            $table->text('account_name_ciphertext')->nullable();
            $table->char('currency_code', 3);
            $table->string('status', 32)->default('provisioning');
            $table->string('client_reference', 100);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'client_reference'], 'deposit_account_business_ref_unique');
            $table->unique(['provider', 'provider_account_id'], 'deposit_account_provider_id_unique');
            $table->unique(['provider', 'account_number_hash'], 'deposit_account_provider_number_unique');
            $table->index(['business_id', 'user_id', 'status'], 'deposit_account_owner_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_deposit_accounts');
    }
};
