<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_membership_invitations')) {
            Schema::create('business_membership_invitations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->string('email', 254);
                $table->string('role', 40);
                $table->char('token_hash', 64)->unique();
                $table->unsignedBigInteger('invited_by_user_id');
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->unsignedBigInteger('accepted_by_user_id')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'email', 'accepted_at', 'revoked_at'], 'membership_invites_lookup_idx');
                $table->index(['expires_at', 'accepted_at', 'revoked_at'], 'membership_invites_expiry_idx');
            });
        }

        if (! Schema::hasTable('business_audit_events')) {
            Schema::create('business_audit_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('business_id')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('business_audit_events');
        Schema::dropIfExists('business_membership_invitations');
    }
};
