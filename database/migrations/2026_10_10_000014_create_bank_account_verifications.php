<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_account_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('verification_provider', 32);
            $table->string('bank_code', 32);
            $table->string('bank_name', 160);
            $table->text('account_number_ciphertext');
            $table->text('account_name_ciphertext');
            $table->string('status', 20)->default('verified');
            $table->timestamp('verified_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['business_id', 'user_id', 'expires_at'], 'bank_verification_owner_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_verifications');
    }
};
