<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_deposit_accounts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('recovery_attempts')->default(0);
            $table->timestamp('last_recovery_at')->nullable();
            $table->string('last_recovery_result', 32)->nullable();
            $table->index(['status', 'last_recovery_at'], 'deposit_account_recovery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_deposit_accounts', function (Blueprint $table): void {
            $table->dropIndex('deposit_account_recovery_idx');
            $table->dropColumn(['recovery_attempts', 'last_recovery_at', 'last_recovery_result']);
        });
    }
};
