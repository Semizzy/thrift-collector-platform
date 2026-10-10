<?php

namespace App\Domain\Tenancy;

enum BusinessPermission: string
{
    case ViewMembers = 'members.view';
    case ManageMembers = 'members.manage';
    case ViewArrangements = 'arrangements.view';
    case ManageArrangements = 'arrangements.manage';
    case RecordContributions = 'contributions.record';
    case ConfirmCollections = 'collections.confirm';
    case ViewReports = 'reports.view';
    case ReviewDisputes = 'disputes.review';
    case ManageReconciliation = 'reconciliation.manage';
    case ManagePayments = 'payments.manage';

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        return match ($role) {
            'business_owner' => array_column(self::cases(), 'value'),
            'business_manager' => [
                self::ViewMembers->value, self::ManageMembers->value,
                self::ViewArrangements->value, self::ManageArrangements->value,
                self::RecordContributions->value, self::ConfirmCollections->value,
                self::ViewReports->value, self::ReviewDisputes->value, self::ManageReconciliation->value, self::ManagePayments->value,
            ],
            'field_agent' => [self::ViewMembers->value, self::ViewArrangements->value, self::RecordContributions->value],
            'group_organizer' => [self::ViewMembers->value, self::ViewArrangements->value, self::ViewReports->value],
            'auditor' => [self::ViewMembers->value, self::ViewArrangements->value, self::ViewReports->value, self::ReviewDisputes->value, self::ManageReconciliation->value, self::ManagePayments->value],
            'contributor' => [self::ViewArrangements->value],
            default => [],
        };
    }

    public static function allows(string $role, string $permission): bool
    {
        return in_array($permission, self::forRole($role), true);
    }
}
