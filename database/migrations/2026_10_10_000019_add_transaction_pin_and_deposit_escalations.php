<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('transaction_pin_hash', 255)->nullable();
            $table->timestamp('transaction_pin_set_at')->nullable();
            $table->unsignedTinyInteger('transaction_pin_failed_attempts')->default(0);
            $table->timestamp('transaction_pin_locked_until')->nullable();
        });

        Schema::create('deposit_account_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_deposit_account_id')->constrained('user_deposit_accounts')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 1000);
            $table->string('status', 24)->default('open');
            $table->string('diagnostic_code', 48)->nullable();
            $table->timestamps();
            $table->unique('user_deposit_account_id', 'deposit_escalation_one_per_account');
            $table->index(['business_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_account_escalations');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'transaction_pin_hash', 'transaction_pin_set_at',
                'transaction_pin_failed_attempts', 'transaction_pin_locked_until',
            ]);
        });
    }
};
