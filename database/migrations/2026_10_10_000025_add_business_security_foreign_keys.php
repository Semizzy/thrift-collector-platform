<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $hasForeignKey = static function (string $tableName, string $constraintName): bool {
            return collect(Schema::getForeignKeys($tableName))
                ->contains(static fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === $constraintName);
        };

        if (! $hasForeignKey('business_membership_invitations', 'business_membership_invitations_business_id_foreign')) {
            Schema::table('business_membership_invitations', function ($table): void {
                $table->foreign('business_id', 'business_membership_invitations_business_id_foreign')
                    ->references('id')->on('businesses')->cascadeOnDelete();
            });
        }
        if (! $hasForeignKey('business_membership_invitations', 'business_membership_invitations_invited_by_user_id_foreign')) {
            Schema::table('business_membership_invitations', function ($table): void {
                $table->foreign('invited_by_user_id', 'business_membership_invitations_invited_by_user_id_foreign')
                    ->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! $hasForeignKey('business_membership_invitations', 'business_membership_invitations_accepted_by_user_id_foreign')) {
            Schema::table('business_membership_invitations', function ($table): void {
                $table->foreign('accepted_by_user_id', 'business_membership_invitations_accepted_by_user_id_foreign')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }
        if (! $hasForeignKey('business_audit_events', 'business_audit_events_business_id_foreign')) {
            Schema::table('business_audit_events', function ($table): void {
                $table->foreign('business_id', 'business_audit_events_business_id_foreign')
                    ->references('id')->on('businesses')->nullOnDelete();
            });
        }
        if (! $hasForeignKey('business_audit_events', 'business_audit_events_actor_user_id_foreign')) {
            Schema::table('business_audit_events', function ($table): void {
                $table->foreign('actor_user_id', 'business_audit_events_actor_user_id_foreign')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Keep referential-integrity constraints in place during rollback; removing them
        // could silently weaken an existing database that already had these constraints.
    }
};
