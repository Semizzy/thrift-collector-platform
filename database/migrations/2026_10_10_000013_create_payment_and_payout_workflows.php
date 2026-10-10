<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_gateway_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('direction', 16);
            $table->string('status', 20)->default('disabled');
            $table->json('public_config')->nullable();
            $table->text('credentials_ciphertext')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'provider', 'direction'], 'gateway_business_provider_direction_unique');
        });

        Schema::create('deposit_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('method', 24)->default('gateway');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('client_reference', 100);
            $table->string('provider_reference', 160)->nullable();
            $table->string('proof_path', 255)->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'client_reference'], 'deposit_business_client_ref_unique');
            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['provider', 'provider_reference']);
        });

        Schema::create('payout_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('method', 24)->default('gateway');
            $table->string('status', 24)->default('requested');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->text('destination_ciphertext');
            $table->string('client_reference', 100);
            $table->string('provider_reference', 160)->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('paid_confirmed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'client_reference'], 'payout_business_client_ref_unique');
            $table->index(['business_id', 'status', 'created_at']);
        });

        Schema::create('payment_operation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('operation_type', 24);
            $table->unsignedBigInteger('operation_id');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 40);
            $table->string('reason', 500)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'operation_type', 'operation_id', 'created_at'], 'payment_operation_events_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_operation_events');
        Schema::dropIfExists('payout_requests');
        Schema::dropIfExists('deposit_intents');
        Schema::dropIfExists('payment_gateway_configs');
    }
};
