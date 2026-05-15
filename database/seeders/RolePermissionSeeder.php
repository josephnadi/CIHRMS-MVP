<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class RolePermissionSeeder extends Seeder
{
    /**
     * Canonical permission catalog. Grouped for the admin UI.
     * Add new permissions here — the seeder is idempotent.
     */
    private const PERMISSIONS = [
        // Dashboard
        'dashboard.view'         => ['Dashboard',    'View dashboard'],

        // Employees
        'employees.view'         => ['Employees',    'View employee directory'],
        'employees.manage'       => ['Employees',    'Create / edit / terminate employees'],
        'employees.view_salary'  => ['Employees',    'View employee salary information'],
        'employees.transfer'     => ['Employees',    'Transfer employees between departments'],

        // Leave
        'leave.request'          => ['Leave',        'Request leave for self'],
        'leave.approve'          => ['Leave',        'Approve / reject leave requests'],
        'leave.manage'           => ['Leave',        'Edit any leave request'],

        // Tickets
        'tickets.create'         => ['Service Desk', 'Open service tickets'],
        'tickets.manage'         => ['Service Desk', 'Triage and resolve tickets'],

        // Complaints
        'complaints.create'      => ['Complaints',   'Submit complaints'],
        'complaints.manage'      => ['Complaints',   'Manage and update complaints'],

        // Recruitment
        'recruitment.apply'      => ['Recruitment',  'Apply for jobs'],
        'recruitment.manage'     => ['Recruitment',  'Post jobs and manage applicants'],

        // Payroll
        'payroll.view'           => ['Payroll',      'View payroll register'],
        'payroll.manage'         => ['Payroll',      'Process and post payments'],

        // Reports
        'reports.view'           => ['Reports',      'View and export reports'],

        // Audit
        'audit.view'             => ['Audit',        'View audit log'],

        // Integrations
        'integrations.manage'    => ['System',       'Manage integrations and webhooks'],

        // System / RBAC
        'roles.manage'           => ['System',       'Create / edit roles and permissions'],
        'users.manage'           => ['System',       'Create / edit user accounts'],

        // ── Phase 1: Statutory payroll ──
        'payroll.run'            => ['Payroll',      'Initiate and calculate payroll runs'],
        'payroll.approve'        => ['Payroll',      'Approve a calculated payroll run'],
        'payroll.reverse'        => ['Payroll',      'Reverse an approved or paid payroll run'],
        'payroll.view_all'       => ['Payroll',      'View all payroll runs across departments'],
        'statutory.export'       => ['Payroll',      'Download statutory return files (PAYE/SSNIT/Tier-2)'],

        // ── Phase 1: Establishment ──
        'positions.view'         => ['Establishment','View positions and org structure'],
        'positions.manage'       => ['Establishment','Create / edit / freeze / assign positions'],
        'establishment.exceed'   => ['Establishment','Override approved-headcount ceilings (with audit)'],
        'grades.manage'          => ['Establishment','Manage grades and salary steps'],

        // ── Phase 1: Identity verification ──
        'identity.view'          => ['Identity',     'View Ghana Card verification records'],
        'identity.verify'        => ['Identity',     'Submit Ghana Card verifications'],

        // ── Phase 2: Time & Attendance ──
        'attendance.view'        => ['Attendance',   'View attendance records org-wide'],
        'attendance.manage'      => ['Attendance',   'Manual entries, device management, corrections'],
        'attendance.clock_self'  => ['Attendance',   'Clock self in/out (employee self-service)'],
        'attendance.shift_manage'=> ['Attendance',   'Manage shift schedules and assignments'],
        'attendance.approve'     => ['Attendance',   'Approve or reject attendance correction requests'],
        'attendance.correct'     => ['Attendance',   'Request a manual correction to own attendance'],

        // ── Phase 2: Loans & Advances ──
        'loans.view'             => ['Loans',        'View all loan accounts org-wide'],
        'loans.apply'            => ['Loans',        'Apply for a loan (self or on-behalf for HR)'],
        'loans.approve'          => ['Loans',        'Approve / reject loan applications'],
        'loans.disburse'         => ['Loans',        'Disburse approved loans and generate schedule'],
        'loans.manage'           => ['Loans',        'Full administrative access to loans'],
        'loans.product_manage'   => ['Loans',        'Manage loan product catalogue'],

        // ── Phase 2: Off-boarding ──
        'offboarding.view'       => ['Off-boarding', 'View off-boarding cases'],
        'offboarding.initiate'   => ['Off-boarding', 'Open a new off-boarding case'],
        'offboarding.clear'      => ['Off-boarding', 'Sign off clearance items (department reps)'],
        'offboarding.settle'     => ['Off-boarding', 'Calculate final settlements'],
        'offboarding.approve'    => ['Off-boarding', 'Approve final settlements (dual control)'],
        'offboarding.manage'     => ['Off-boarding', 'Complete, cancel, and administer cases'],

        // ── Phase 2: Whistleblower (Act 720) ──
        // Segregated investigation role — assignment deliberately kept away from HR
        // line management so that retaliation pressure doesn't flow through HR.
        'whistleblower.investigate' => ['Whistleblower', 'Triage and investigate cases (segregated role)'],
        'whistleblower.manage'      => ['Whistleblower', 'Reassign, delete, full administrative access'],
        'whistleblower.view_all'    => ['Whistleblower', 'Read-only access (Auditor lane)'],

        // ── Phase 3: Assets ──
        'assets.view'            => ['Assets',       'View asset registry'],
        'assets.manage'          => ['Assets',       'Register, assign, return, retire assets'],
        'assets.assign'          => ['Assets',       'Assign assets within own department'],

        // ── Phase 4: Benefits ──
        'benefits.view'      => ['Benefits',     'View own benefits, plans, claims'],
        'benefits.view_all'  => ['Benefits',     'View all employees benefits org-wide'],
        'benefits.manage'    => ['Benefits',     'Manage benefit plans + claim decisions'],
        'benefits.enrol'     => ['Benefits',     'Enrol in benefit plans (self)'],
        'benefits.claim'     => ['Benefits',     'Submit benefit claims (self)'],
    ];

    /**
     * Permission slugs to grant per system role.
     * super_admin gets the wildcard '*' through hasPermission(); we still mirror perms here.
     */
    private const ROLE_PERMS = [
        'super_admin' => null, // null = grant ALL permissions
        'hr_admin' => [
            'dashboard.view', 'employees.view', 'employees.manage', 'employees.transfer',
            'employees.view_salary',
            'leave.request', 'leave.approve', 'leave.manage',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'complaints.manage',
            'recruitment.apply', 'recruitment.manage',
            'payroll.view', 'payroll.run', 'payroll.view_all',
            'positions.view', 'positions.manage', 'grades.manage',
            'identity.view', 'identity.verify',
            'attendance.view', 'attendance.manage', 'attendance.clock_self', 'attendance.shift_manage',
            'attendance.approve', 'attendance.correct',
            'loans.view', 'loans.apply', 'loans.manage', 'loans.product_manage',
            'offboarding.view', 'offboarding.initiate', 'offboarding.clear',
            'offboarding.settle', 'offboarding.manage',
            'assets.view', 'assets.manage', 'assets.assign',
            'benefits.view', 'benefits.view_all', 'benefits.manage', 'benefits.enrol', 'benefits.claim',
            'reports.view',
            'integrations.manage', 'users.manage',
        ],
        'manager' => [
            'dashboard.view', 'employees.view',
            'leave.request', 'leave.approve',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply',
            'attendance.view', 'attendance.clock_self',
            'attendance.approve', 'attendance.correct',
            'assets.view', 'assets.assign',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'reports.view',
        ],
        'dept_head' => [
            'dashboard.view', 'employees.view', 'employees.transfer',
            'leave.request', 'leave.approve',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply',
            'positions.view',
            'attendance.view', 'attendance.clock_self',
            'attendance.approve', 'attendance.correct',
            'assets.view', 'assets.assign',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'reports.view',
        ],
        'employee' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'attendance.clock_self', 'attendance.correct',
            'loans.apply',
            'assets.view',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
        ],
        'finance_officer' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'payroll.view', 'payroll.manage',
            'payroll.approve', 'payroll.view_all', 'statutory.export',
            'employees.view_salary',
            'attendance.correct',
            'loans.view', 'loans.apply', 'loans.approve', 'loans.disburse',
            'offboarding.view', 'offboarding.settle', 'offboarding.approve',
            'assets.view',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'reports.view',
        ],
        'it_support' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply',
            'attendance.correct',
            'assets.view', 'assets.manage', 'assets.assign',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
        ],
        'auditor' => [
            'dashboard.view', 'employees.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'reports.view', 'audit.view',
            'payroll.view_all', 'positions.view', 'identity.view', 'statutory.export',
            'attendance.view', 'attendance.correct',
            'assets.view',
            // Whistleblower: read-only access for independent oversight.
            // Auditor can ALSO investigate when designated by the org as the
            // segregated investigator role.
            'whistleblower.view_all', 'whistleblower.investigate',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
        ],
    ];

    private const ROLE_LABELS = [
        'super_admin'     => 'Super Administrator',
        'hr_admin'        => 'HR Administrator',
        'manager'         => 'Line Manager',
        'dept_head'       => 'Department Head',
        'employee'        => 'Employee',
        'finance_officer' => 'Finance Officer',
        'it_support'      => 'IT Support',
        'auditor'         => 'Auditor',
    ];

    public function run(): void
    {
        // 1. Permissions
        foreach (self::PERMISSIONS as $slug => [$group, $description]) {
            Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'        => str_replace('.', ': ', $slug),
                    'group'       => $group,
                    'description' => $description,
                ]
            );
        }

        // 2. Roles + permission attach
        $allPermIds = Permission::pluck('id', 'slug');

        foreach (self::ROLE_PERMS as $slug => $perms) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'      => self::ROLE_LABELS[$slug] ?? ucfirst(str_replace('_', ' ', $slug)),
                    'is_system' => true,
                ]
            );

            $ids = $perms === null
                ? $allPermIds->values()->all()
                : $allPermIds->only($perms)->values()->all();

            $role->permissions()->sync($ids);
        }

        // 3. Backfill: every existing user gets a user_roles entry matching their primary role.
        User::query()->each(function (User $user) {
            $slug = $user->role?->value;
            if (! $slug) return;

            $role = Role::where('slug', $slug)->first();
            if (! $role) return;

            $user->roles()->syncWithoutDetaching([
                $role->id => ['department_id' => null],
            ]);
        });

        // 4. Phase 1 — flag privileged roles as 2FA-required.
        User::query()
            ->whereIn('role', ['super_admin', 'hr_admin', 'finance_officer'])
            ->update(['two_factor_required' => true]);

        Cache::flush();
    }
}
