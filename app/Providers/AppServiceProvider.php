<?php

namespace App\Providers;

use App\Events\EmployeeCreated;
use App\Events\LeaveRequested;
use App\Events\LeaveStatusUpdated;
use App\Events\TicketCreated;
use App\Integrations\IntegrationManager;
use App\Integrations\MessagingDispatcher;
use App\Integrations\OAuth\OAuthFlow;
use App\Integrations\OAuth\TokenRefresher;
use App\Integrations\OAuth\TokenStore;
use App\Listeners\RecordAnalyticsEvent;
use App\Listeners\SendNotifications;
use App\Events\PayrollRunApproved;
use App\Listeners\GenerateStatutoryReturns;
use App\Models\Department;
use App\Models\Employee;
use App\Models\IdentityVerification;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\Ticket;
use App\Policies\DepartmentPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\IdentityVerificationPolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PayrollRunPolicy;
use App\Policies\PositionPolicy;
use App\Policies\TicketPolicy;
use App\Services\Payroll\PayrollService;
use App\Services\Payroll\StatutoryReturnGenerator;
use App\Services\Establishment\PositionService;
use App\Services\Establishment\StepIncrementService;
use App\Services\Identity\IdentityVerificationService;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\OvertimeCalculator;
use App\Services\Attendance\BiometricIngestionService;
use App\Services\Loans\AmortizationCalculator;
use App\Services\Loans\LoanService;
use App\Models\AttendanceRecord;
use App\Models\LoanAccount;
use App\Policies\AttendancePolicy;
use App\Policies\LoanAccountPolicy;
use App\Services\Auth\TwoFactorService;
use App\Services\ComplaintService;
use App\Services\DashboardService;
use App\Services\EmployeeService;
use App\Services\LearningService;
use App\Services\LeaveService;
use App\Services\PaymentService;
use App\Services\PerformanceService;
use App\Services\RecruitmentService;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmployeeService::class);
        $this->app->singleton(LeaveService::class);
        $this->app->singleton(TicketService::class);
        $this->app->singleton(ComplaintService::class);
        $this->app->singleton(RecruitmentService::class);
        $this->app->singleton(PaymentService::class);
        $this->app->singleton(DashboardService::class);
        $this->app->singleton(PerformanceService::class);
        $this->app->singleton(LearningService::class);

        // Phase 1 — Statutory payroll engine + establishment + identity + 2FA
        $this->app->singleton(PayrollService::class);
        $this->app->singleton(StatutoryReturnGenerator::class);
        $this->app->singleton(PositionService::class);
        $this->app->singleton(StepIncrementService::class);
        $this->app->singleton(IdentityVerificationService::class);
        $this->app->singleton(TwoFactorService::class);

        // Phase 2 — Time & Attendance
        $this->app->singleton(AttendanceService::class);
        $this->app->singleton(OvertimeCalculator::class);
        $this->app->singleton(BiometricIngestionService::class);
        $this->app->singleton(\App\Services\Attendance\ShiftService::class);

        // Phase 2 — Loans & Advances
        $this->app->singleton(AmortizationCalculator::class);
        $this->app->singleton(LoanService::class);

        // Phase 2 — Off-boarding & Final Settlement
        $this->app->singleton(\App\Services\Offboarding\FinalSettlementCalculator::class);
        $this->app->singleton(\App\Services\Offboarding\OffboardingService::class);

        // Phase 2 — Whistleblower (Act 720)
        $this->app->singleton(\App\Services\Whistleblower\TrackingCodeGenerator::class);
        $this->app->singleton(\App\Services\Whistleblower\WhistleblowerSubmissionService::class);
        $this->app->singleton(\App\Services\Whistleblower\WhistleblowerInvestigationService::class);

        // Phase 2 — Performance Management completion
        $this->app->singleton(\App\Services\Performance\PerformanceContractService::class);
        $this->app->singleton(\App\Services\Performance\CalibrationService::class);
        $this->app->singleton(\App\Services\Performance\PipService::class);

        // Integrations layer (Wave 9)
        $this->app->singleton(TokenStore::class);
        $this->app->singleton(OAuthFlow::class);
        $this->app->singleton(TokenRefresher::class);
        $this->app->singleton(IntegrationManager::class, fn ($app) => new IntegrationManager($app));

        // Messaging layer (Wave 12)
        $this->app->singleton(MessagingDispatcher::class);

        // Phase 3 — Assets
        $this->app->singleton(\App\Services\AssetService::class);

        // Phase 4 — Benefits
        $this->app->singleton(\App\Services\BenefitsService::class);

        // Phase 5 — Governance
        $this->app->singleton(\App\Services\GovernanceService::class);
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Strict mode catches lazy loading, mass assignment, and missing attributes in development
        Model::shouldBeStrict(! $this->app->isProduction());

        // ── Authorization policies (M11 RBAC + Phase 1) ──
        Gate::policy(Employee::class,              EmployeePolicy::class);
        Gate::policy(LeaveRequest::class,          LeaveRequestPolicy::class);
        Gate::policy(Ticket::class,                TicketPolicy::class);
        Gate::policy(Payment::class,               PaymentPolicy::class);
        Gate::policy(Department::class,            DepartmentPolicy::class);
        Gate::policy(PayrollRun::class,            PayrollRunPolicy::class);
        Gate::policy(Position::class,              PositionPolicy::class);
        Gate::policy(IdentityVerification::class,  IdentityVerificationPolicy::class);
        Gate::policy(AttendanceRecord::class,      AttendancePolicy::class);
        Gate::policy(LoanAccount::class,           LoanAccountPolicy::class);
        Gate::policy(\App\Models\OffboardingCase::class,     \App\Policies\OffboardingCasePolicy::class);
        Gate::policy(\App\Models\WhistleblowerReport::class, \App\Policies\WhistleblowerReportPolicy::class);
        Gate::policy(\App\Models\PerformanceContract::class, \App\Policies\PerformanceContractPolicy::class);
        Gate::policy(\App\Models\CalibrationSession::class,  \App\Policies\CalibrationSessionPolicy::class);
        Gate::policy(\App\Models\PerformanceImprovementPlan::class, \App\Policies\PerformanceImprovementPlanPolicy::class);
        Gate::policy(\App\Models\Asset::class,               \App\Policies\AssetPolicy::class);
        Gate::policy(\App\Models\BenefitPlan::class,         \App\Policies\BenefitsPolicy::class);
        Gate::policy(\App\Models\BenefitEnrolment::class,    \App\Policies\BenefitsPolicy::class);
        Gate::policy(\App\Models\BenefitClaim::class,        \App\Policies\BenefitsPolicy::class);

        // ── Generic permission gate: $user->can('perm.slug') falls through to hasPermission() ──
        Gate::before(function ($user, string $ability) {
            if (str_contains($ability, '.') && method_exists($user, 'hasPermission')) {
                return $user->hasPermission($ability) ?: null;
            }
            return null;
        });

        // Event → Listener wiring (queued via RecordAnalyticsEvent::ShouldQueue)
        Event::listen(EmployeeCreated::class, RecordAnalyticsEvent::class);
        Event::listen(LeaveRequested::class, RecordAnalyticsEvent::class);
        Event::listen(LeaveStatusUpdated::class, RecordAnalyticsEvent::class);
        Event::listen(TicketCreated::class, RecordAnalyticsEvent::class);

        Event::listen(LeaveStatusUpdated::class, SendNotifications::class);
        Event::listen(EmployeeCreated::class, SendNotifications::class);

        // Phase 2 — Attendance correction lifecycle events
        Event::listen(\App\Events\AttendanceCorrectionRequested::class, \App\Listeners\RecordAnalyticsEvent::class);
        Event::listen(\App\Events\AttendanceCorrectionDecided::class, \App\Listeners\RecordAnalyticsEvent::class);

        // Phase 1 — Payroll run approval triggers statutory return generation
        Event::listen(PayrollRunApproved::class, GenerateStatutoryReturns::class);

        // Wave 10 — UploadPayslipToCloud is auto-discovered via its typed PayslipGenerated parameter.

        // Phase 3 — Assets
        Event::listen(\App\Events\AssetAssigned::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\AssetReturned::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\AssetMaintenanceLogged::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\AssetMaintenanceCompleted::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\AssetRetired::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\AssetMarkedLost::class, RecordAnalyticsEvent::class);

        // Phase 4 — Benefits
        Event::listen(\App\Events\BenefitPlanCreated::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\BenefitEnroled::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\DependantAdded::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\BenefitClaimSubmitted::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\BenefitClaimDecided::class, RecordAnalyticsEvent::class);

        // Phase 5 — Governance
        Event::listen(\App\Events\PolicyDrafted::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\PolicyVersionAdded::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\PolicyPublished::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\PolicyAcknowledged::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\CertificationExpiring::class, RecordAnalyticsEvent::class);
        Event::listen(\App\Events\CertificationExpired::class, RecordAnalyticsEvent::class);
    }
}
