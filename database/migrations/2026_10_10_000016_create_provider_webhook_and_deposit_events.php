<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('provider_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->char('event_key', 64);
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('event_type', 100);
            $table->string('provider_object_id', 160)->nullable();
            $table->string('status', 32)->default('received');
            $table->char('payload_hash', 64);
            $table->json('safe_metadata')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_key'], 'provider_webhook_event_unique');
            $table->index(['business_id', 'status', 'received_at'], 'provider_webhook_review_idx');
        });

        Schema::create('provider_deposit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_deposit_account_id')->nullable()->constrained('user_deposit_accounts')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('provider_transaction_id', 120);
            $table->string('provider_reference', 160)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 32)->default('needs_review');
            $table->char('payload_hash', 64);
            $table->json('safe_metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_decision', 32)->nullable();
            $table->string('review_reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'provider', 'provider_transaction_id'], 'provider_deposit_transaction_unique');
            $table->index(['business_id', 'status', 'created_at'], 'provider_deposit_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_deposit_events');
        Schema::dropIfExists('provider_webhook_events');
    }
};
