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

        // Integrations layer (Wave 9)
        $this->app->singleton(TokenStore::class);
        $this->app->singleton(OAuthFlow::class);
        $this->app->singleton(TokenRefresher::class);
        $this->app->singleton(IntegrationManager::class, fn ($app) => new IntegrationManager($app));

        // Messaging layer (Wave 12)
        $this->app->singleton(MessagingDispatcher::class);
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

        // Phase 1 — Payroll run approval triggers statutory return generation
        Event::listen(PayrollRunApproved::class, GenerateStatutoryReturns::class);

        // Wave 10 — UploadPayslipToCloud is auto-discovered via its typed PayslipGenerated parameter.
    }
}
