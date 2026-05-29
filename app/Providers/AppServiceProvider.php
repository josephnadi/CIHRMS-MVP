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
use App\Models\Document;
use App\Models\Employee;
use App\Models\IdentityVerification;
use App\Models\IncidentReport;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\Ticket;
use App\Policies\DepartmentPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\IdentityVerificationPolicy;
use App\Policies\IncidentReportPolicy;
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
use Illuminate\Support\Facades\URL;
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

        // Phase 3 — Bank-change two-factor confirmation. Service is bound
        // explicitly because its constructor takes the active SmsProvider,
        // which the container needs help resolving from `messaging.php`.
        $this->app->singleton(\App\Services\BankChangeRequestService::class, function ($app) {
            return new \App\Services\BankChangeRequestService(
                $app->make(\App\Services\Messaging\Sms\Contracts\SmsProvider::class),
            );
        });

        // Phase 2 — Oracle IPPD2/IPPD3 export. Construction needs the MDA
        // code + output disk from config, so we bind it explicitly rather
        // than letting Reflection-based auto-resolve fail on the strings.
        $this->app->singleton(\App\Services\Payroll\Ippd\IppdExporter::class, function () {
            return new \App\Services\Payroll\Ippd\IppdExporter(
                mdaCode: (string) config('payroll.ippd.mda_code', 'CIHRMS'),
                disk:    (string) config('payroll.ippd.output_disk', 'local'),
            );
        });

        // Phase 2 — GIFMIS journal-voucher exporter. GL-code map pulled from
        // config so each MDA can override their chart-of-accounts entries
        // via env without forking the service.
        $this->app->singleton(\App\Services\Payroll\Gifmis\GifmisJournalExporter::class, function () {
            return new \App\Services\Payroll\Gifmis\GifmisJournalExporter(
                costCentre: (string) config('payroll.gifmis.cost_centre', '0000-00-00'),
                glCodes:    (array)  config('payroll.gifmis.gl_codes', []),
                disk:       (string) config('payroll.gifmis.output_disk', 'local'),
            );
        });
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

        // Phase 3 — DPA 2012 Data-Subject Portal
        $this->app->singleton(\App\Services\Privacy\DataSubjectExportBuilder::class);
        $this->app->singleton(\App\Services\Privacy\ErasureService::class);
        $this->app->singleton(\App\Services\Privacy\DataSubjectRequestService::class);

        // Phase 3 — Public API v1 + webhooks
        $this->app->singleton(\App\Services\Api\WebhookDispatcher::class);

        // Phase 3 — Webhook dispatcher (WS14)
        $this->app->singleton(\App\Services\Webhooks\WebhookDispatcher::class);

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

        // Phase 4 — AI assistant. The LlmProvider binding resolves to either
        // the real Anthropic SDK or a deterministic fake based on config/ai.php.
        // The fake is also used whenever AI_ENABLED=false so the controller
        // never has to special-case "AI is off" — it just gets a canned reply.
        $this->app->singleton(\App\Services\Ai\Contracts\LlmProvider::class, function () {
            $enabled = (bool) config('ai.enabled', false);
            $driver  = (string) config('ai.driver', 'anthropic');

            if (! $enabled || $driver === 'fake') {
                return new \App\Services\Ai\Providers\FakeLlmProvider();
            }

            if ($driver === 'anthropic') {
                $cfg = (array) config('ai.providers.anthropic', []);
                $key = (string) ($cfg['api_key'] ?? '');

                // Misconfigured tenant — fall back to the fake rather than
                // 500-ing every summary request. The fake reply is clearly
                // labeled so operators see they need to set ANTHROPIC_API_KEY.
                if ($key === '') {
                    return new \App\Services\Ai\Providers\FakeLlmProvider();
                }

                return new \App\Services\Ai\Providers\AnthropicLlmProvider(
                    apiKey:    $key,
                    model:     (string) ($cfg['model'] ?? 'claude-haiku-4-5'),
                    maxTokens: (int)    ($cfg['max_tokens'] ?? 400),
                    timeout:   (int)    ($cfg['timeout'] ?? 20),
                );
            }

            return new \App\Services\Ai\Providers\FakeLlmProvider();
        });

        $this->app->singleton(\App\Services\Ai\PiiRedactor::class);
        $this->app->singleton(\App\Services\Ai\EmployeeSummaryService::class);
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Belt-and-braces HTTPS enforcement. The load balancer typically
        // redirects HTTP → HTTPS, but if the app is ever served directly or
        // a misconfigured proxy forwards plain HTTP, this forces every
        // generated URL (including signed routes) onto https://.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // ── API rate-limiter (referenced by throttle:api on /api/v1/* routes) ──
        // 60/min per token (auth'd) or per IP (anonymous) — generous for HRMS
        // partner traffic but low enough to flag a misbehaving integration.
        \Illuminate\Support\Facades\RateLimiter::for('api', function ($request) {
            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(60)
                    ->by($request->user()?->id ?: $request->ip()),
            ];
        });

        // ── SMS rate limiters (N1 reliability — N3 broadcast inherits) ──
        // Transactional context bypasses throttling: "leave approved" SMS must
        // always reach the user. We still register the limiter so the
        // dispatcher API surface is symmetric and downstream broadcast code
        // (N3) can opt into a tighter limiter without conditional logic.
        \Illuminate\Support\Facades\RateLimiter::for('sms:transactional', function ($key) {
            return [\Illuminate\Cache\RateLimiting\Limit::none()];
        });

        // Marketing context — 5 messages per phone per hour. Used by the
        // admin broadcast surface in N3; in N1 only the limiter registration
        // ships, so nothing currently hits this path.
        \Illuminate\Support\Facades\RateLimiter::for('sms:marketing', function ($key) {
            return [\Illuminate\Cache\RateLimiting\Limit::perHour(5)->by($key)];
        });

        // Strict mode — opt in selectively. We keep:
        //   • preventLazyLoading             — catches N+1 in dev
        //   • preventSilentlyDiscardingAttributes — catches typos in fillable
        // but DO NOT enable preventAccessingMissingAttributes, because
        // middleware (LocaleResolver, ForcePasswordChange, …) routinely
        // reads optional User columns that aren't always present on factory
        // or partially-hydrated instances — strict mode would 500 those flows.
        $strict = ! $this->app->isProduction();
        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);

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
        Gate::policy(IncidentReport::class,            IncidentReportPolicy::class);
        Gate::policy(\App\Models\IncidentReportAttachment::class, IncidentReportPolicy::class);
        Gate::policy(\App\Models\OffboardingCase::class,     \App\Policies\OffboardingCasePolicy::class);
        Gate::policy(\App\Models\WhistleblowerReport::class, \App\Policies\WhistleblowerReportPolicy::class);
        Gate::policy(\App\Models\PerformanceContract::class, \App\Policies\PerformanceContractPolicy::class);
        Gate::policy(\App\Models\CalibrationSession::class,  \App\Policies\CalibrationSessionPolicy::class);
        Gate::policy(\App\Models\PerformanceImprovementPlan::class, \App\Policies\PerformanceImprovementPlanPolicy::class);
        Gate::policy(\App\Models\DataSubjectRequest::class,         \App\Policies\DataSubjectRequestPolicy::class);
        Gate::policy(\App\Models\Asset::class,               \App\Policies\AssetPolicy::class);
        Gate::policy(\App\Models\BenefitPlan::class,         \App\Policies\BenefitsPolicy::class);
        Gate::policy(\App\Models\BenefitEnrolment::class,    \App\Policies\BenefitsPolicy::class);
        Gate::policy(\App\Models\BenefitClaim::class,        \App\Policies\BenefitsPolicy::class);
        Gate::policy(\App\Models\Policy::class,              \App\Policies\GovernancePolicy::class);
        Gate::policy(Document::class,                        DocumentPolicy::class);
        Gate::policy(\App\Models\DocumentAnnotation::class,  DocumentPolicy::class);
        Gate::policy(\App\Models\StampAsset::class,          \App\Policies\StampAssetPolicy::class);

        // Billing & Fees (M1) — Members + Fee catalog + Fee assignments.
        Gate::policy(\App\Models\Member::class,         \App\Policies\MemberPolicy::class);
        Gate::policy(\App\Models\FeeProduct::class,     \App\Policies\FeeProductPolicy::class);
        Gate::policy(\App\Models\FeeAssignment::class,  \App\Policies\FeeAssignmentPolicy::class);
        Gate::policy(\App\Models\LetterheadTemplate::class,  \App\Policies\LetterheadTemplatePolicy::class);
        Gate::policy(\App\Models\WatermarkTemplate::class,   \App\Policies\WatermarkTemplatePolicy::class);
        Gate::policy(\App\Models\Conversation::class,        \App\Policies\ConversationPolicy::class);
        Gate::policy(\App\Models\ChatMessage::class,         \App\Policies\ConversationPolicy::class);

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

        // Phase 3 — Approval also materialises disbursement instructions
        // (HR/Finance then dispatches them explicitly via the UI).
        Event::listen(PayrollRunApproved::class, \App\Listeners\MaterialiseDisbursements::class);

        // Phase 2 — When a run is actually paid (not just approved), auto-mint
        // the GIFMIS journal voucher so the state accountant has the sub-ledger
        // file waiting. Gated on payroll.gifmis.auto_mint_on_paid (off by default).
        Event::listen(\App\Events\PayrollRunPaid::class, \App\Listeners\MintGifmisJournal::class);

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

        // Incident Reporting — Notification listeners (Task 8)
        // These are auto-discovered by Laravel 11's listener scanner (typed
        // handle() parameters).  Explicit Event::listen() registrations here
        // would double-register them, firing each listener twice per event.

        // ── N2 notifications: loans ──
        Event::listen(\App\Events\LoanApproved::class,    \App\Listeners\Notifications\SendLoanNotifications::class);
        Event::listen(\App\Events\LoanDisbursed::class,   \App\Listeners\Notifications\SendLoanNotifications::class);
        Event::listen(\App\Events\LoanFullyRepaid::class, \App\Listeners\Notifications\SendLoanNotifications::class);

        // ── N2 notifications: benefits ──
        Event::listen(\App\Events\BenefitClaimSubmitted::class, \App\Listeners\Notifications\SendBenefitsNotifications::class);
        Event::listen(\App\Events\BenefitClaimDecided::class,   \App\Listeners\Notifications\SendBenefitsNotifications::class);
    }
}
