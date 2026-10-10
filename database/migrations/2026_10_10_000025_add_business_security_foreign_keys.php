<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $hasForeignKey = static function (string $tableName, string $columnName, string $referencedTable): bool {
            return collect(Schema::getForeignKeys($tableName))->contains(
                static fn (array $foreignKey): bool =>
                    in_array($columnName, $foreignKey['columns'] ?? [], true)
                    && ($foreignKey['foreign_table'] ?? null) === $referencedTable
            );
        };

        if (! $hasForeignKey('business_membership_invitations', 'business_id', 'businesses')) {
            Schema::table('business_membership_invitations', function ($table): void {
                $table->foreign('business_id', 'business_membership_invitations_business_id_foreign')
                    ->references('id')->on('businesses')->cascadeOnDelete();
            });
        }
        if (! $hasForeignKey('business_membership_invitations', 'invited_by_user_id', 'users')) {
            Schema::table('business_membership_invitations', function ($table): void {
                $table->foreign('invited_by_user_id', 'business_membership_invitations_invited_by_user_id_foreign')
                    ->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! $hasForeignKey('business_membership_invitations', 'accepted_by_user_id', 'users')) {
            Schema::table('business_membership_invitations', function ($table): void {
                $table->foreign('accepted_by_user_id', 'business_membership_invitations_accepted_by_user_id_foreign')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }
        if (! $hasForeignKey('business_audit_events', 'business_id', 'businesses')) {
            Schema::table('business_audit_events', function ($table): void {
                $table->foreign('business_id', 'business_audit_events_business_id_foreign')
                    ->references('id')->on('businesses')->nullOnDelete();
            });
        }
        if (! $hasForeignKey('business_audit_events', 'actor_user_id', 'users')) {
            Schema::table('business_audit_events', function ($table): void {
                $table->foreign('actor_user_id', 'business_audit_events_actor_user_id_foreign')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $hasForeignKey = static function (string $tableName, string $columnName, string $referencedTable): bool {
            return collect(Schema::getForeignKeys($tableName))->contains(
                static fn (array $foreignKey): bool =>
                    in_array($columnName, $foreignKey['columns'] ?? [], true)
                    && ($foreignKey['foreign_table'] ?? null) === $referencedTable
            );
        };

        foreach ([
            ['business_audit_events', 'actor_user_id', 'users', 'business_audit_events_actor_user_id_foreign'],
            ['business_audit_events', 'business_id', 'businesses', 'business_audit_events_business_id_foreign'],
            ['business_membership_invitations', 'accepted_by_user_id', 'users', 'business_membership_invitations_accepted_by_user_id_foreign'],
            ['business_membership_invitations', 'invited_by_user_id', 'users', 'business_membership_invitations_invited_by_user_id_foreign'],
            ['business_membership_invitations', 'business_id', 'businesses', 'business_membership_invitations_business_id_foreign'],
        ] as [$tableName, $columnName, $referencedTable, $constraintName]) {
            if (Schema::hasTable($tableName) && $hasForeignKey($tableName, $columnName, $referencedTable)) {
                Schema::table($tableName, static function (Blueprint $table) use ($constraintName): void {
                    $table->dropForeign($constraintName);
                });
            }
        }
    }
};
