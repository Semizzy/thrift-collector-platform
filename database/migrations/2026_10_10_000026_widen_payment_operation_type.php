<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_operation_events', function (Blueprint $table): void {
            // Values include names such as "bank_account_verification" (25 chars).
            // Keep this column wide enough for operation types used by the payment API.
            $table->string('operation_type', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_operation_events', function (Blueprint $table): void {
            $table->string('operation_type', 24)->change();
        });
    }
};
