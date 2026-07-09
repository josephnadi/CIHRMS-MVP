<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Typed catalogue of every permission slug the codebase grants or checks.
 * L10 audit fix — replaces error-prone bare-string `hasPermission('foo.bar')`
 * call sites that silently failed on typo.
 *
 * `User::hasPermission()` accepts either `Permission|string`, so existing
 * string call sites keep working. New code should prefer the enum so the
 * IDE catches typos at lint time.
 *
 *   $user->hasPermission(Permission::EmployeesManage);
 *
 * When adding a new permission: add the case here AND the slug to
 * `RolePermissionSeeder::PERMISSIONS`. The seeder is the source of truth
 * for grouping + description; this enum is the source of truth for the
 * slug string itself.
 */
enum Permission: string
{
    case DashboardView           = 'dashboard.view';

    case EmployeesView           = 'employees.view';
    case EmployeesManage         = 'employees.manage';
    case EmployeesViewSalary     = 'employees.view_salary';
    case EmployeesTransfer       = 'employees.transfer';

    case LeaveRequest            = 'leave.request';
    case LeaveApprove            = 'leave.approve';
    case LeaveManage             = 'leave.manage';

    case TicketsCreate           = 'tickets.create';
    case TicketsManage           = 'tickets.manage';

    case ComplaintsCreate        = 'complaints.create';
    case ComplaintsManage        = 'complaints.manage';

    case RecruitmentApply        = 'recruitment.apply';
    case RecruitmentManage       = 'recruitment.manage';

    case PayrollView             = 'payroll.view';
    case PayrollManage           = 'payroll.manage';
    case PayrollRun              = 'payroll.run';
    case PayrollApprove          = 'payroll.approve';
    case PayrollReverse          = 'payroll.reverse';
    case PayrollViewAll          = 'payroll.view_all';
    case StatutoryExport         = 'statutory.export';
    case PayrollDisburse         = 'payroll.disburse';

    case ReportsView             = 'reports.view';
    case AuditView               = 'audit.view';

    case AuditorHub              = 'auditor.hub';
    case IncomingInvoicesView    = 'incoming_invoices.view';
    case IncomingInvoicesSubmit  = 'incoming_invoices.submit';
    case IncomingInvoicesVet     = 'incoming_invoices.vet';
    case IncomingInvoicesApprove = 'incoming_invoices.approve';
    case IncomingInvoicesPost    = 'incoming_invoices.post';

    case IntegrationsManage      = 'integrations.manage';
    case RolesManage             = 'roles.manage';
    case UsersManage             = 'users.manage';

    case PositionsView           = 'positions.view';
    case PositionsManage         = 'positions.manage';
    case EstablishmentExceed     = 'establishment.exceed';
    case GradesManage            = 'grades.manage';

    case IdentityView            = 'identity.view';
    case IdentityVerify          = 'identity.verify';

    case AttendanceView          = 'attendance.view';
    case AttendanceManage        = 'attendance.manage';
    case AttendanceClockSelf     = 'attendance.clock_self';
    case AttendanceShiftManage   = 'attendance.shift_manage';
    case AttendanceApprove       = 'attendance.approve';
    case AttendanceCorrect       = 'attendance.correct';

    case LoansView               = 'loans.view';
    case LoansApply              = 'loans.apply';
    case LoansApprove            = 'loans.approve';
    case LoansDisburse           = 'loans.disburse';
    case LoansManage             = 'loans.manage';
    case LoansProductManage      = 'loans.product_manage';

    case OffboardingView         = 'offboarding.view';
    case OffboardingInitiate     = 'offboarding.initiate';
    case OffboardingClear        = 'offboarding.clear';
    case OffboardingSettle       = 'offboarding.settle';
    case OffboardingApprove      = 'offboarding.approve';
    case OffboardingManage       = 'offboarding.manage';

    case WhistleblowerInvestigate = 'whistleblower.investigate';
    case WhistleblowerManage      = 'whistleblower.manage';
    case WhistleblowerViewAll     = 'whistleblower.view_all';

    case PrivacyFulfill          = 'privacy.fulfill';
    case PrivacyErase            = 'privacy.erase';

    case ApiTokenManage          = 'api.token_manage';
    case ApiWebhooksManage       = 'api.webhooks_manage';

    case PerformanceView            = 'performance.view';
    case PerformanceManage          = 'performance.manage';
    case PerformanceCalibrate       = 'performance.calibrate';
    case PerformanceCalibrateApply  = 'performance.calibrate_apply';
    case PerformancePipManage       = 'performance.pip_manage';

    case LearningView               = 'learning.view';
    case LearningManage             = 'learning.manage';
    case LearningComplianceManage   = 'learning.compliance.manage';

    case AssetsView              = 'assets.view';
    case AssetsManage            = 'assets.manage';
    case AssetsAssign            = 'assets.assign';

    case MessagingView           = 'messaging.view';
    case MessagingSend           = 'messaging.send';
    case MessagingManage         = 'messaging.manage';

    // ── N3: Broadcasts (admin SMS+mail to pre-defined audiences) ──
    case BroadcastsView            = 'broadcasts.view';
    case BroadcastsManage          = 'broadcasts.manage';
    case BroadcastsBypassThrottle  = 'broadcasts.bypass_throttle';

    case SsoManage               = 'sso.manage';
    case SsoAuditView            = 'sso.audit_view';

    case BenefitsView            = 'benefits.view';
    case BenefitsViewAll         = 'benefits.view_all';
    case BenefitsManage          = 'benefits.manage';
    case BenefitsEnrol           = 'benefits.enrol';
    case BenefitsClaim           = 'benefits.claim';

    case GovernanceView          = 'governance.view';
    case GovernanceManage        = 'governance.manage';
    case GovernanceAcknowledge   = 'governance.acknowledge';
    case GovernanceCertManage    = 'governance.cert_manage';

    case AnnouncementsManage     = 'announcements.manage';

    case AccountsView            = 'accounts.view';
    case AccountsManage          = 'accounts.manage';
    case BankAccountsView        = 'bank_accounts.view';
    case BankAccountsManage      = 'bank_accounts.manage';
    case FinanceHub              = 'finance.hub';

    case VendorsView             = 'vendors.view';
    case VendorsManage           = 'vendors.manage';
    case ApInvoicesView          = 'ap_invoices.view';
    case ApInvoicesCreate        = 'ap_invoices.create';
    case ApInvoicesApprove       = 'ap_invoices.approve';
    case ApInvoicesPay           = 'ap_invoices.pay';
    case JournalView             = 'journal.view';
    case JournalPostManual       = 'journal.post_manual';

    case CustomersView           = 'customers.view';
    case CustomersManage         = 'customers.manage';
    case ArInvoicesView          = 'ar_invoices.view';
    case ArInvoicesCreate        = 'ar_invoices.create';
    case ArInvoicesApprove       = 'ar_invoices.approve';
    case ArInvoicesReceive       = 'ar_invoices.receive';
    case ArInvoicesWriteOff      = 'ar_invoices.write_off';
    case StatementsView          = 'statements.view';

    case GatewayView             = 'gateway.view';
    case GatewayCreate           = 'gateway.create';
    case GatewayRefund           = 'gateway.refund';

    case ReconciliationView      = 'reconciliation.view';
    case ReconciliationImport    = 'reconciliation.import';
    case ReconciliationMatch     = 'reconciliation.match';
    case ReconciliationAdjust    = 'reconciliation.adjust';

    case IncidentsReview         = 'incidents.review';

    case AiUse                   = 'ai.use';

    // ── M1: Billing & Fees ──
    case MembersView             = 'members.view';
    case MembersManage           = 'members.manage';
    case FeeCatalogView          = 'fee_catalog.view';
    case FeeCatalogManage        = 'fee_catalog.manage';
    case BillingRun              = 'billing.run';
    case BillingCancel           = 'billing.cancel';
}
