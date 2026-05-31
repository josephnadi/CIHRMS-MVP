<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pre-defined audience targets for admin broadcasts. Each value maps to a
 * resolver in `App\Services\Messaging\Broadcasts\AudienceResolver` that
 * returns an Eloquent Builder, and to a variable-whitelist + recipient-type
 * binding enforced by `TemplateRenderer`.
 */
enum BroadcastAudienceType: string
{
    case AllActiveMembers            = 'all_active_members';
    case MembersByClass              = 'members_by_class';
    case MembersWithOutstandingFees  = 'members_with_outstanding_fees';
    case AllActiveEmployees          = 'all_active_employees';
    case EmployeesByDepartment       = 'employees_by_department';
    case UsersByPermission           = 'users_by_permission';

    /** The model class instances of this audience type are. */
    public function recipientClass(): string
    {
        return match ($this) {
            self::AllActiveMembers, self::MembersByClass, self::MembersWithOutstandingFees
                => \App\Models\Member::class,
            self::AllActiveEmployees, self::EmployeesByDepartment
                => \App\Models\Employee::class,
            self::UsersByPermission
                => \App\Models\User::class,
        };
    }

    /** Variable names allowed in templates for this audience type. */
    public function allowedVariables(): array
    {
        $common = ['org_name', 'today'];
        $specific = match ($this) {
            self::AllActiveMembers, self::MembersByClass, self::MembersWithOutstandingFees => [
                'member.name', 'member.member_no', 'member.class',
                'member.outstanding_total', 'member.next_due_date',
            ],
            self::AllActiveEmployees, self::EmployeesByDepartment => [
                'employee.name', 'employee.staff_id', 'employee.department', 'employee.position',
            ],
            self::UsersByPermission => [
                'user.name', 'user.role',
            ],
        };
        return array_merge($common, $specific);
    }
}
