<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin    = 'super_admin';
    case Ceo           = 'ceo';
    case HrAdmin       = 'hr_admin';
    case Manager       = 'manager';
    case DeptHead      = 'dept_head';
    case Employee      = 'employee';
    case FinanceOfficer = 'finance_officer';
    case ItSupport     = 'it_support';
    case Marketing     = 'marketing';
    case Auditor       = 'auditor';

    public function label(): string
    {
        return match($this) {
            self::SuperAdmin     => 'Super Admin',
            self::Ceo            => 'Chief Executive Officer',
            self::HrAdmin        => 'HR Admin',
            self::Manager        => 'Manager',
            self::DeptHead       => 'Department Head',
            self::Employee       => 'Employee',
            self::FinanceOfficer => 'Finance Officer',
            self::ItSupport      => 'IT Support',
            self::Marketing      => 'Marketing',
            self::Auditor        => 'Auditor',
        };
    }
}
