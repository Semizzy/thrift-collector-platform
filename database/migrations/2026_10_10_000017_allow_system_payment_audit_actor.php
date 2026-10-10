<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_operation_events', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // System-generated provider webhook events intentionally have no human actor.
        // Do not make the column non-null again because those immutable audit records
        // must remain valid after a rollback of later feature migrations.
    }
};
