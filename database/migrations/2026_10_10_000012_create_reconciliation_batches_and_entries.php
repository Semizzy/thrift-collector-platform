<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reconciliation_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('source', 40);
            $table->string('source_reference', 160);
            $table->char('currency_code', 3);
            $table->string('status', 20)->default('review');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('entry_count')->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'source', 'source_reference'], 'recon_batch_source_ref_unique');
            $table->index(['business_id', 'status', 'created_at']);
        });

        Schema::create('reconciliation_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_batch_id')->constrained('reconciliation_batches')->restrictOnDelete();
            $table->string('source_transaction_id', 160)->nullable();
            $table->string('external_reference', 160)->nullable();
            $table->string('normalized_reference', 160)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->timestamp('occurred_at')->nullable();
            $table->string('status', 20)->default('unmatched');
            $table->foreignId('matched_contribution_record_id')->nullable()->constrained('contribution_records')->restrictOnDelete();
            $table->char('row_fingerprint', 64);
            $table->json('source_data')->nullable();
            $table->timestamps();
            $table->unique(['reconciliation_batch_id', 'row_fingerprint'], 'recon_entry_fingerprint_unique');
            $table->index(['reconciliation_batch_id', 'status']);
            $table->index(['normalized_reference', 'currency_code', 'amount_minor'], 'recon_entry_match_idx');
        });

        Schema::create('reconciliation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('reconciliation_entry_id')->constrained('reconciliation_entries')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 30);
            $table->string('reason', 500);
            $table->json('evidence')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_decisions');
        Schema::dropIfExists('reconciliation_entries');
        Schema::dropIfExists('reconciliation_batches');
    }
};
