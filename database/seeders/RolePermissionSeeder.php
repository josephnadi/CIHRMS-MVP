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
        'statutory.remit'        => ['Payroll',      'Record a statutory return as filed/remitted'],
        'payroll.disburse'       => ['Payroll',      'Dispatch and reconcile disbursements (MoMo/GhIPSS)'],

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

        // ── Learning & Development ──
        'learning.view'              => ['Learning', 'View the learning catalogue, own enrolments and skills'],
        'learning.manage'            => ['Learning', 'Manage courses, the skills matrix and assignments'],
        'learning.compliance.manage' => ['Learning', 'Manage mandatory-training compliance requirements + dashboard'],

        // ── Phase 2: Onboarding ──
        'onboarding.view'        => ['Onboarding', 'View onboarding cases'],
        'onboarding.initiate'    => ['Onboarding', 'Open a new onboarding case'],
        'onboarding.complete'    => ['Onboarding', 'Sign off onboarding tasks and complete cases'],
        'onboarding.manage'      => ['Onboarding', 'Cancel and administer onboarding cases'],

        // ── Phase 2: Whistleblower (Act 720) ──
        // Segregated investigation role — assignment deliberately kept away from HR
        // line management so that retaliation pressure doesn't flow through HR.
        'whistleblower.investigate' => ['Whistleblower', 'Triage and investigate cases (segregated role)'],
        'whistleblower.manage'      => ['Whistleblower', 'Reassign, delete, full administrative access'],
        'whistleblower.view_all'    => ['Whistleblower', 'Read-only access (Auditor lane)'],

        // ── Phase 3: DPA 2012 (Act 843) ──
        // Self-service rights are inherent — no permission needed. These two
        // gate the Data Protection Officer (DPO) lane.
        'privacy.fulfill'           => ['Privacy', 'Fulfil/reject data-subject requests (DPO role)'],
        'privacy.erase'             => ['Privacy', 'Execute right-to-erasure tombstoning (super-admin gate)'],

        // ── Phase 3: Public API (WS16) ──
        'api.token_manage'          => ['API',     'Issue and revoke partner API tokens'],
        'api.webhooks_manage'       => ['API',     'Register and manage webhook subscriptions'],

        // ── Phase 2: Performance Management completion ──
        'performance.view'            => ['Performance', 'View performance contracts, reviews, calibration and PIPs'],
        'performance.manage'          => ['Performance', 'Create / edit performance contracts and record evaluations'],
        // Calibration uses dual-control: facilitator locks, a different user applies.
        'performance.calibrate'       => ['Performance', 'Facilitate calibration sessions and record adjustments'],
        'performance.calibrate_apply' => ['Performance', 'Apply locked calibration adjustments (dual control)'],
        'performance.pip_manage'      => ['Performance', 'Open / extend / close Performance Improvement Plans'],

        // ── Phase 3: Assets ──
        'assets.view'            => ['Assets',       'View asset registry'],
        'assets.manage'          => ['Assets',       'Register, assign, return, retire assets'],
        'assets.assign'          => ['Assets',       'Assign assets within own department'],

        // ── Phase 3: Messaging (SMS / USSD) ──
        'messaging.view'    => ['Messaging', 'View SMS log and inbound messages'],
        'messaging.send'    => ['Messaging', 'Send one-off SMS to a phone number'],
        'messaging.manage'  => ['Messaging', 'Issue / rotate USSD self-service PINs'],

        // ── N3: Broadcasts (admin SMS+mail to pre-defined audiences) ──
        'broadcasts.view'             => ['Broadcasts', 'View broadcast history + recipient outcomes'],
        'broadcasts.manage'           => ['Broadcasts', 'Compose, schedule, send, cancel broadcasts'],
        'broadcasts.bypass_throttle'  => ['Broadcasts', 'Bypass the sms:marketing per-phone rate limiter on a broadcast (audit-logged)'],

        // ── Phase 4: SSO (NITA / OIDC / SAML) ──
        'sso.manage'        => ['SSO',       'Configure identity providers (NITA, Azure, ghana.gov)'],
        'sso.audit_view'    => ['SSO',       'View SSO login attempt audit log'],

        // ── Phase 4: Benefits ──
        'benefits.view'      => ['Benefits',     'View own benefits, plans, claims'],
        'benefits.view_all'  => ['Benefits',     'View all employees benefits org-wide'],
        'benefits.manage'    => ['Benefits',     'Manage benefit plans + claim decisions'],
        'benefits.enrol'     => ['Benefits',     'Enrol in benefit plans (self)'],
        'benefits.claim'     => ['Benefits',     'Submit benefit claims (self)'],

        // ── Phase 5: Governance ──
        'governance.view'         => ['Governance', 'View policies and acknowledge them'],
        'governance.manage'       => ['Governance', 'Create / edit / publish policies'],
        'governance.acknowledge'  => ['Governance', 'Acknowledge published policies (self)'],
        'governance.cert_manage'  => ['Governance', 'Manage certification records + reminders'],

        // ── Communications: notice board / ticker ──
        'announcements.manage'    => ['Communications', 'Create, edit, and remove org-wide notices on the ticker'],

        // ── F1: Finance — Chart of Accounts & Org Banking ──
        'accounts.view'        => ['Finance', 'View chart of accounts'],
        'accounts.manage'      => ['Finance', 'Create / edit / archive GL accounts'],
        'bank_accounts.view'   => ['Finance', 'View organisational bank accounts'],
        'bank_accounts.manage' => ['Finance', 'Manage organisational bank accounts'],
        'finance.hub'          => ['Finance', 'Access the Finance Hub landing page'],
        'finance.posting_rules.manage' => ['Finance', 'View and re-map the GL account-determination rules'],
        'finance.period.view'   => ['Finance', 'View the fiscal calendar and period statuses'],
        'finance.period.close'  => ['Finance', 'Close a fiscal period (month-end)'],
        'finance.period.reopen' => ['Finance', 'Reopen a closed fiscal period'],
        'finance.period.lock'   => ['Finance', 'Permanently lock a fiscal period (post-audit)'],
        'finance.reports.view'  => ['Finance', 'View financial statements (trial balance, P&L, balance sheet, cash flow)'],
        'finance.analytics.view' => ['Finance', 'View the finance analytics dashboard (KPIs, charts)'],
        'finance.budget.manage' => ['Finance', 'Create / edit / approve annual budgets'],

        // ── F2: Finance — Accounts Payable + Journal Engine ──
        'vendors.view'         => ['Finance', 'View vendor master data'],
        'vendors.manage'       => ['Finance', 'Create / edit / archive vendors'],
        'ap_invoices.view'     => ['Finance', 'View vendor invoices'],
        'ap_invoices.create'   => ['Finance', 'Create / submit vendor invoices'],
        'ap_invoices.approve'  => ['Finance', 'Approve / cancel vendor invoices'],
        'ap_invoices.pay'      => ['Finance', 'Record / void AP payments and trigger disbursement'],
        'journal.view'         => ['Finance', 'View posted journal entries (audit)'],
        'journal.post_manual'  => ['Finance', 'Create / post manual journal entries (emergency)'],

        // ── F3: Finance — Accounts Receivable ──
        'customers.view'           => ['Finance', 'View customer master data'],
        'customers.manage'         => ['Finance', 'Create / edit / archive customers'],
        'ar_invoices.view'         => ['Finance', 'View AR invoices'],
        'ar_invoices.create'       => ['Finance', 'Create / submit AR invoices'],
        'ar_invoices.approve'      => ['Finance', 'Approve / cancel AR invoices'],
        'ar_invoices.receive'      => ['Finance', 'Record / void AR receipts against invoices'],
        'ar_invoices.write_off'    => ['Finance', 'Write off uncollectible AR invoices as bad debt'],
        'statements.view'          => ['Finance', 'View customer statements (date-range, running balance)'],

        // ── F4: Finance — Paystack Gateway ──
        'gateway.view'   => ['Finance', 'View payment intents and gateway events'],
        'gateway.create' => ['Finance', 'Generate Paystack payment links'],
        'gateway.refund' => ['Finance', 'Refund a processed Paystack payment'],

        // ── F5: Finance — Bank Reconciliation ──
        'reconciliation.view'   => ['Finance', 'View bank statements and reconciliation status'],
        'reconciliation.import' => ['Finance', 'Upload bank statement files'],
        'reconciliation.match'  => ['Finance', 'Link statement lines to AP payments / AR receipts'],
        'reconciliation.adjust' => ['Finance', 'Post bank fee or interest adjustment journal entries'],

        // ── AI assistant (audit-v2 tier-3 supplement, item 28) ──
        // Gates per-call LLM provider cost; executives + line management only.
        'ai.use'                => ['AI', 'Use AI assistant features'],

        // ── M1: Billing & Fees (Members + Fee Catalog + Billing runs) ──
        'members.view'        => ['Billing', 'View the CIHRM member directory'],
        'members.manage'      => ['Billing', 'Create / edit / remove CIHRM members'],
        'fee_catalog.view'    => ['Billing', 'View the fee catalog'],
        'fee_catalog.manage'  => ['Billing', 'Manage fee products in the catalog'],
        'billing.run'         => ['Billing', 'Execute billing runs (mint AR invoices from fee assignments)'],
        'billing.cancel'      => ['Billing', 'Cancel a pending fee assignment'],
    ];

    /**
     * Permission slugs to grant per system role.
     * super_admin gets the wildcard '*' through hasPermission(); we still mirror perms here.
     */
    private const ROLE_PERMS = [
        'super_admin' => null, // null = grant ALL permissions
        // CEO mirrors super_admin permission-wise (full access). Kept as a
        // distinct role for org-chart / audit / reporting reasons; the chief
        // executive must not hit a permission wall on any module.
        'ceo' => null,
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
            'onboarding.view', 'onboarding.initiate', 'onboarding.complete', 'onboarding.manage',
            'performance.view', 'performance.manage', 'performance.calibrate', 'performance.pip_manage',
            'learning.view', 'learning.manage', 'learning.compliance.manage',
            'assets.view', 'assets.manage', 'assets.assign',
            'messaging.view', 'messaging.send', 'messaging.manage',
            'broadcasts.view', 'broadcasts.manage', 'broadcasts.bypass_throttle',
            'sso.manage', 'sso.audit_view',
            'benefits.view', 'benefits.view_all', 'benefits.manage', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.manage', 'governance.acknowledge', 'governance.cert_manage',
            'announcements.manage',
            'reports.view',
            'integrations.manage', 'users.manage',
            // AI assistant — executives + HR line management get LLM-backed tooling.
            'ai.use',
            // M1 — HR owns the member directory (CIHRM members + students).
            'members.view', 'members.manage',
            'fee_catalog.view',
        ],
        'manager' => [
            'dashboard.view', 'employees.view',
            'leave.request', 'leave.approve',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply',
            'attendance.view', 'attendance.clock_self',
            'attendance.approve', 'attendance.correct',
            'performance.view', 'performance.manage',
            'learning.view', 'learning.manage', 'learning.compliance.manage',
            'assets.view', 'assets.assign',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
            'reports.view',
            // AI assistant — line managers get LLM-backed tooling.
            'ai.use',
        ],
        'dept_head' => [
            'dashboard.view', 'employees.view', 'employees.transfer',
            'leave.request', 'leave.approve',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply',
            'positions.view',
            'attendance.view', 'attendance.clock_self',
            'attendance.approve', 'attendance.correct',
            'performance.view', 'performance.manage',
            'learning.view', 'learning.manage', 'learning.compliance.manage',
            'assets.view', 'assets.assign',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
            'reports.view',
        ],
        'employee' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'attendance.clock_self', 'attendance.correct',
            'loans.apply',
            'performance.view', 'learning.view',
            'assets.view',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
        ],
        'finance_officer' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'payroll.view', 'payroll.run', 'payroll.manage',
            'payroll.approve', 'payroll.view_all', 'statutory.export', 'statutory.remit',
            'employees.view_salary',
            'attendance.correct',
            'loans.view', 'loans.apply', 'loans.approve', 'loans.disburse',
            'payroll.disburse',
            'offboarding.view', 'offboarding.settle', 'offboarding.approve',
            'onboarding.view',
            'learning.view',
            'assets.view',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
            'reports.view',
            // F1 — Finance Hub & Chart of Accounts
            'accounts.view', 'accounts.manage',
            'bank_accounts.view', 'bank_accounts.manage',
            'finance.hub',
            'finance.posting_rules.manage',
            'finance.period.view', 'finance.period.close', 'finance.period.reopen',
            'finance.reports.view',
            'finance.analytics.view',
            'finance.budget.manage',
            // F2 — Accounts Payable & Journal
            'vendors.view', 'vendors.manage',
            'ap_invoices.view', 'ap_invoices.create', 'ap_invoices.approve', 'ap_invoices.pay',
            'journal.view',
            // F3 — Accounts Receivable
            'customers.view', 'customers.manage',
            'ar_invoices.view', 'ar_invoices.create', 'ar_invoices.approve',
            'ar_invoices.receive', 'ar_invoices.write_off',
            'statements.view',
            // F4 — Paystack Gateway
            'gateway.view', 'gateway.create', 'gateway.refund',
            // F5 — Bank Reconciliation
            'reconciliation.view', 'reconciliation.import', 'reconciliation.match', 'reconciliation.adjust',
            // M1 — Billing & Fees (finance owns the catalog and runs)
            'members.view',
            'fee_catalog.view', 'fee_catalog.manage',
            'billing.run', 'billing.cancel',
            // N3 — Broadcasts
            'broadcasts.view', 'broadcasts.manage',
        ],
        'it_support' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply',
            'attendance.correct',
            'learning.view',
            'assets.view', 'assets.manage', 'assets.assign',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
        ],
        'auditor' => [
            'dashboard.view', 'employees.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'reports.view', 'audit.view',
            'payroll.view_all', 'positions.view', 'identity.view', 'statutory.export',
            'attendance.view', 'attendance.correct',
            'learning.view',
            'assets.view',
            // Whistleblower: read-only access for independent oversight.
            // Auditor can ALSO investigate when designated by the org as the
            // segregated investigator role.
            'whistleblower.view_all', 'whistleblower.investigate',
            // Performance: auditor holds the dual-control APPLY side of calibration
            // — facilitates can adjust ratings, but a different user with apply
            // rights commits them to the underlying reviews.
            'performance.calibrate_apply',
            // Privacy: Auditor doubles as the Data Protection Officer (DPO) so
            // data-subject requests don't flow through HR (avoids conflict of
            // interest where HR processes their own employees' erasure requests).
            'privacy.fulfill',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
            // F1 — Finance read-only oversight
            'accounts.view', 'bank_accounts.view',
            'finance.reports.view',
            'finance.analytics.view',
            // F2 — Read-only oversight
            'vendors.view', 'ap_invoices.view', 'journal.view',
            // F3 — Read-only oversight
            'customers.view', 'ar_invoices.view', 'statements.view',
            // F4 — Read-only gateway oversight
            'gateway.view',
            // F5 — Read-only reconciliation oversight
            'reconciliation.view',
        ],
    ];

    private const ROLE_LABELS = [
        'super_admin'     => 'Super Administrator',
        'ceo'             => 'Chief Executive Officer',
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

        // 4. Phase 1 — flag privileged roles as 2FA-required. CEO included
        // because executive sign-offs (payroll.approve, loans.approve) carry
        // material financial weight and warrant a second factor.
        User::query()
            ->whereIn('role', ['super_admin', 'ceo', 'hr_admin', 'finance_officer'])
            ->update(['two_factor_required' => true]);

        // 5. Backfill: any existing CEO / super-admin user gets the wildcard
        // in their per-user permissions JSON so a curated set written before
        // the role was promoted to full-access (e.g. by an earlier seed run
        // or by Admin/UserController::store before this update) doesn't
        // shadow the wildcard. The legacy fallback in User::ROLE_PERMISSIONS
        // already covers hasPermission() reads — this just keeps the JSON
        // column from showing stale data in the admin UI.
        User::query()
            ->whereIn('role', ['super_admin', 'ceo'])
            ->update(['permissions' => json_encode(['*'])]);

        Cache::flush();
    }
}
