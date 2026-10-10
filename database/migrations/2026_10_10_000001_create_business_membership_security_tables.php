<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_membership_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('role', 40);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'email', 'accepted_at', 'revoked_at'], 'membership_invites_lookup_idx');
            $table->index(['expires_at', 'accepted_at', 'revoked_at'], 'membership_invites_expiry_idx');
        });

        Schema::create('business_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'created_at'], 'business_audit_business_created_idx');
            $table->index(['subject_type', 'subject_id'], 'business_audit_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_audit_events');
        Schema::dropIfExists('business_membership_invitations');
    }
};
