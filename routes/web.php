<?php

use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\GovernanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\NotificationChannelController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileDocumentController;
use App\Http\Controllers\RecruitmentController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WorkforceAnalyticsController;
use App\Http\Controllers\PayrollRunController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\IdentityVerificationController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\BenefitsController;
use App\Http\Controllers\LoanAccountController;
use App\Http\Controllers\OffboardingController;
use App\Http\Controllers\WhistleblowerPublicController;
use App\Http\Controllers\WhistleblowerAdminController;
use App\Http\Controllers\AuditorGeneralReportController;
use App\Http\Controllers\DisbursementController;
use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\WebhookSubscriptionController as WebhooksController;
use App\Http\Controllers\MessagingController;
use App\Http\Controllers\PerformanceContractController;
use App\Http\Controllers\CalibrationController;
use App\Http\Controllers\PipController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\Webhooks\BiometricWebhookController;
use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\Finance\ChartOfAccountsController;
use App\Http\Controllers\Finance\FinanceHubController;
use App\Http\Controllers\Finance\OrgBankAccountController;
use App\Http\Controllers\Webhooks\ESignWebhookController;
use App\Http\Controllers\Webhooks\WebhookController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use App\Http\Controllers\Webhooks\ZohoWebhookController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin'       => Route::has('login'),
        'canRegister'    => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion'     => PHP_VERSION,
    ]);
});

// Public API documentation (Stoplight Elements rendered from /api/v1/openapi.yaml)
// `api.docs` is the Inertia landing page; `api.docs.interactive` is the
// full-bleed Stoplight reference (kept on a separate route so the landing
// can iframe it without redirect).
Route::get('/api/docs',         [ApiDocsController::class, 'show'])       ->name('api.docs');
Route::get('/api/docs/explore', [ApiDocsController::class, 'interactive'])->name('api.docs.interactive');

// PWA offline fallback (WS21) — served from a plain Blade view so it loads
// without Inertia / Vite / authenticated state.
Route::view('/offline', 'offline')->name('pwa.offline');

// Public careers portal (unauthenticated)
Route::get('/careers/{job}',        [RecruitmentController::class, 'showPublic'])->name('careers.show');
Route::post('/careers/{job}/apply', [RecruitmentController::class, 'apply'])
    ->middleware('throttle:5,1')
    ->name('careers.apply');

// ── Public whistleblower channel (anonymous; Whistleblower Act 2006 / Act 720) ──
// Rate-limited to discourage flooding while still allowing legitimate use.
Route::prefix('whistleblower')->name('whistleblower.')->middleware('throttle:6,1')->group(function () {
    Route::get('/',              [WhistleblowerPublicController::class, 'submitForm'])->name('form');
    Route::post('/',             [WhistleblowerPublicController::class, 'submit'])->name('submit');
    Route::get('/confirmation',  [WhistleblowerPublicController::class, 'confirmation'])->name('confirmation');
    Route::get('/track',         [WhistleblowerPublicController::class, 'trackForm'])->name('track');
    Route::post('/track',        [WhistleblowerPublicController::class, 'track'])->name('track.submit');
    Route::post('/track/reply',  [WhistleblowerPublicController::class, 'reply'])->name('track.reply');
});

// Inbound webhooks (public; signature-verified per provider)
Route::prefix('webhooks')->name('webhooks.')->group(function () {
    Route::match(['get', 'post'], '/whatsapp', [WhatsAppWebhookController::class, 'handle'])
        ->middleware('webhook.signature:whatsapp')
        ->name('whatsapp');
    Route::post('/zoho', [ZohoWebhookController::class, 'handle'])
        ->middleware('webhook.signature:zoho')
        ->name('zoho');
    Route::post('/esign', [ESignWebhookController::class, 'handle'])
        ->middleware('webhook.signature:esign')
        ->name('esign');
    Route::post('/ms-graph', [WebhookController::class, 'handle'])
        ->defaults('provider', 'ms_graph')
        ->middleware('webhook.signature:ms_graph')
        ->name('msgraph');
    Route::post('/google', [WebhookController::class, 'handle'])
        ->defaults('provider', 'google')
        ->middleware('webhook.signature:google')
        ->name('google');
    Route::post('/slack/events', [WebhookController::class, 'handle'])
        ->defaults('provider', 'slack')
        ->middleware('webhook.signature:slack')
        ->name('slack');

    // Biometric clock-in/out ingest (HMAC-signed per device)
    Route::post('/biometric', [BiometricWebhookController::class, 'handle'])
        ->middleware('webhook.signature:biometric')
        ->name('biometric');

    // SMS provider callbacks (delivery receipts + inbound messages)
    Route::post('/sms', [\App\Http\Controllers\Webhooks\SmsWebhookController::class, 'handle'])
        ->middleware('webhook.signature:hubtel_sms')
        ->name('sms');

    // USSD callback — provider POSTs each menu step here
    Route::post('/ussd', [\App\Http\Controllers\Webhooks\UssdWebhookController::class, 'handle'])
        ->middleware('webhook.signature:hubtel_ussd')
        ->name('ussd');

    // F4 — Paystack hosted-checkout webhook (HMAC-SHA512 signed)
    Route::post('/paystack', [\App\Http\Controllers\Finance\PaystackWebhookController::class, 'handle'])
        ->middleware(['paystack.signature', 'throttle:120,1'])
        ->name('paystack');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Public complaint tracking endpoint — lookup-by-reference, no auth required.
Route::get('/complaints/track', [ComplaintController::class, 'track'])
    ->name('complaints.track');

// Public attendance kiosk — designed for a shared/dedicated device (no auth).
// Identifies employees by employee_no + name (later: face scan) and writes
// clock events via AttendanceService with source=web_kiosk.
Route::prefix('kiosk')->name('kiosk.')->middleware('throttle:60,1')->group(function () {
    Route::get('/',        [KioskController::class, 'show'])->name('show');
    Route::get('/recent',  [KioskController::class, 'recent'])->name('recent');
    Route::post('/verify', [KioskController::class, 'verify'])->name('verify');
    Route::post('/clock',  [KioskController::class, 'clock'])->name('clock');
    Route::post('/face',   [KioskController::class, 'clockByFace'])->name('face');
});

// ── Public DPA 2012 (Act 843) data-subject portal ──────────────────────────
// Any data subject — including ex-employees + failed applicants without a
// CIHRMS login — can file an Access / Erasure / Rectification / Portability
// request. Email verification gates the DPO queue against spam submissions.
Route::prefix('dpa')->name('dpa.')->middleware('throttle:10,1')->group(function () {
    Route::get('/',                [\App\Http\Controllers\PublicDpaController::class, 'form'])->name('form');
    Route::post('/',               [\App\Http\Controllers\PublicDpaController::class, 'submit'])->name('submit');
    Route::get('/confirmation',    [\App\Http\Controllers\PublicDpaController::class, 'confirmation'])->name('confirmation');
    Route::get('/verify',          [\App\Http\Controllers\PublicDpaController::class, 'verify'])->name('verify');
    Route::get('/track',           [\App\Http\Controllers\PublicDpaController::class, 'trackForm'])->name('track');
    Route::post('/track',          [\App\Http\Controllers\PublicDpaController::class, 'track'])->name('track.submit');
});

// ── Module entry points (sidebar links) ─────────────────────────────────────
// Most route directly to a dedicated page; a few that don't have one yet fall
// back to a dashboard redirect via the closure below.
Route::middleware(['auth', 'verified'])->prefix('modules')->name('modules.')->group(function () {
    // Modules that have full dedicated Inertia pages — point at them directly.
    Route::get('employees',   fn () => redirect()->route('employees.index'))                             ->name('employees');
    Route::get('leave',       fn () => redirect()->route('leave.index'))                                 ->name('leave');
    Route::get('tickets',     fn () => redirect()->route('tickets.index'))                               ->name('tickets');
    Route::get('recruitment', fn () => redirect()->route('jobs.index'))                                  ->name('recruitment');
    Route::get('payroll',     fn () => redirect()->route('payments.index'))                              ->name('payroll');
    Route::get('reports',     fn () => redirect()->route('reports.index'))                               ->name('reports');
    Route::get('audit-logs',  fn () => redirect()->route('audit-logs.index'))                            ->name('audit-logs');

    // Modules with dedicated styled-skeleton pages (no real backend yet).
    Route::get('attendance',  fn () => redirect()->route('attendance.index'))                          ->name('attendance');
    Route::get('governance',  fn () => redirect()->route('governance.index'))->name('governance');
    Route::get('assets',      fn () => redirect()->route('assets.index'))->name('assets');
    Route::get('benefits',    fn () => redirect()->route('benefits.index'))->name('benefits');

    // Performance: dedicated analytics page.
    Route::get('performance', [PerformanceController::class, 'index'])                                   ->name('performance');
});

// Department portals (one route, one Vue page, slug-driven)
Route::middleware(['auth', 'verified'])->prefix('departments')->name('departments.')->group(function () {
    Route::get('portal/{slug}', [\App\Http\Controllers\StaticPageController::class, 'department'])
        ->whereIn('slug', ['it', 'hr', 'marketing', 'finance', 'membership', 'pcp', 'cpd', 'administration'])
        ->name('portal');
});

Route::middleware(['auth'])->group(function () {
    // Locale preference (Phase 4 / WS20). Any authenticated user can set their own.
    Route::post('/locale', [\App\Http\Controllers\LocaleController::class, 'update'])->name('locale.update');

    // Profile / Employee Portal
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/',                    [ProfileController::class, 'edit'])           ->name('edit');
        Route::patch('/',                  [ProfileController::class, 'update'])         ->name('update');
        Route::patch('/personal',          [ProfileController::class, 'updatePersonal']) ->name('personal');
        Route::patch('/emergency',         [ProfileController::class, 'updateEmergency'])->name('emergency');
        Route::patch('/bank',              [ProfileController::class, 'updateBank'])     ->name('bank');
        Route::post('/avatar',             [ProfileController::class, 'updateAvatar'])   ->name('avatar');
        Route::patch('/password',          [ProfileController::class, 'updatePassword']) ->name('password');
        Route::delete('/',                 [ProfileController::class, 'destroy'])        ->name('destroy');

        // My Documents — self-service CRUD over the employee's own uploads.
        Route::post('/documents',                 [ProfileDocumentController::class, 'store'])   ->name('documents.store');
        Route::get('/documents/{document}',       [ProfileDocumentController::class, 'download'])->name('documents.download');
        // POST (not PATCH) so the optional file replacement rides a multipart body.
        Route::post('/documents/{document}',      [ProfileDocumentController::class, 'update'])  ->name('documents.update');
        Route::delete('/documents/{document}',    [ProfileDocumentController::class, 'destroy']) ->name('documents.destroy');
    });

    // Departments
    Route::get('/departments',  [EmployeeController::class, 'departments'])
        ->middleware('permission:employees.manage')
        ->name('departments.index');
    Route::post('/departments', [EmployeeController::class, 'storeDepartment'])
        ->middleware('permission:employees.manage')
        ->name('departments.store');
    Route::patch('/departments/{department}', [EmployeeController::class, 'updateDepartment'])
        ->middleware('permission:employees.manage')
        ->name('departments.update');
    Route::delete('/departments/{department}', [EmployeeController::class, 'destroyDepartment'])
        ->middleware('permission:employees.manage')
        ->name('departments.destroy');

    // Employees
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/',                         [EmployeeController::class, 'index'])
            ->middleware('permission:employees.view')
            ->name('index');
        Route::post('/',                        [EmployeeController::class, 'store'])
            ->middleware('permission:employees.manage')
            ->name('store');
        Route::get('{employee}',                [EmployeeController::class, 'show'])
            ->middleware('permission:employees.view')
            ->name('show');
        Route::patch('{employee}',              [EmployeeController::class, 'update'])         ->name('update');
        Route::delete('{employee}',             [EmployeeController::class, 'destroy'])
            ->middleware('permission:employees.manage')
            ->name('destroy');
        Route::post('{employee}/documents',     [EmployeeController::class, 'uploadDocument'])  ->name('documents.store');
        Route::delete('{employee}/documents/{document}', [EmployeeController::class, 'deleteDocument'])->name('documents.destroy');
        Route::post('{employee}/avatar',        [EmployeeController::class, 'uploadAvatar'])    ->name('avatar.store');
        Route::post('{employee}/skills',        [EmployeeController::class, 'storeSkill'])      ->name('skills.store');
        Route::delete('{employee}/skills/{skill}', [EmployeeController::class, 'destroySkill']) ->name('skills.destroy');

        // Signed streaming endpoints for files now stored on the private disk
        // (H10 audit fix). Avatar/document URLs are minted via
        // URL::temporarySignedRoute with a 15-min TTL.
        Route::prefix('{employee}/files')->name('files.')->group(function () {
            Route::get('avatar', [\App\Http\Controllers\EmployeeFileController::class, 'avatar'])
                ->middleware('signed')
                ->name('avatar');
            Route::get('documents/{document}', [\App\Http\Controllers\EmployeeFileController::class, 'document'])
                ->middleware('signed')
                ->scopeBindings()
                ->name('document');
        });
    });

    // Leave requests
    Route::prefix('leave-requests')->name('leave.')->group(function () {
        Route::get('/',                    [LeaveRequestController::class, 'index'])
            ->middleware('permission:leave.request')
            ->name('index');
        Route::post('/',                   [LeaveRequestController::class, 'store'])
            ->middleware('permission:leave.request')
            ->name('store');
        Route::get('{leaveRequest}',       [LeaveRequestController::class, 'show'])
            ->middleware('permission:leave.request')
            ->name('show');
        Route::patch('{leaveRequest}',     [LeaveRequestController::class, 'updateStatus'])
            ->middleware('permission:leave.approve')
            ->name('update');
        Route::delete('{leaveRequest}',    [LeaveRequestController::class, 'destroy'])
            ->middleware('permission:leave.request')
            ->name('destroy');
    });

    // Tickets
    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/',            [TicketController::class, 'index'])
            ->middleware('permission:tickets.create')
            ->name('index');
        Route::post('/',           [TicketController::class, 'store'])
            ->middleware('permission:tickets.create')
            ->name('store');
        Route::get('{ticket}',     [TicketController::class, 'show'])
            ->middleware('permission:tickets.create')
            ->name('show');
        // Authorization is handled by UpdateTicketStatusRequest via TicketPolicy
        // (managers OR the ticket's assignee) — no blanket manage gate here, so
        // an assignee can change the status of their own ticket (drag-to-move).
        Route::patch('{ticket}',   [TicketController::class, 'updateStatus'])
            ->name('update');
        Route::delete('{ticket}',  [TicketController::class, 'destroy'])
            ->middleware('permission:tickets.manage')
            ->name('destroy');
    });

    // Complaints (authenticated routes only — public tracking endpoint lives outside this group)
    Route::prefix('complaints')->name('complaints.')->group(function () {
        Route::get('/',                    [ComplaintController::class, 'index'])
            ->middleware('permission:complaints.create')
            ->name('index');
        Route::post('/',                   [ComplaintController::class, 'store'])
            ->middleware('permission:complaints.create')
            ->name('store');
        Route::patch('{complaint}',        [ComplaintController::class, 'updateStatus'])
            ->middleware('permission:complaints.manage')
            ->name('updateStatus');
    });

    // Recruitment
    Route::prefix('jobs')->name('jobs.')->group(function () {
        Route::get('/',                             [RecruitmentController::class, 'index'])
            ->name('index');
        Route::post('/',                            [RecruitmentController::class, 'createJob'])
            ->middleware('permission:recruitment.manage')
            ->name('store');
        Route::get('{job}',                         [RecruitmentController::class, 'show'])
            ->name('show');
        Route::get('{job}/applicants',              [RecruitmentController::class, 'applicants'])
            ->middleware('permission:recruitment.manage')
            ->name('applicants');
        Route::post('{job}/apply',                  [RecruitmentController::class, 'apply'])
            ->middleware('permission:recruitment.apply')
            ->name('apply');
    });
    Route::patch('applicants/{applicant}', [RecruitmentController::class, 'updateApplicant'])
        ->middleware('permission:recruitment.manage')
        ->name('applicants.update');
    Route::get('applicants/{applicant}/cv', [RecruitmentController::class, 'downloadCv'])
        ->middleware('permission:recruitment.manage')
        ->name('applicants.cv');
    Route::post('applicants/{applicant}/send-offer', [RecruitmentController::class, 'sendOffer'])
        ->middleware('permission:recruitment.manage')
        ->name('applicants.sendOffer');

    // Performance — Goals, Reviews, Cycles, 9-box (Wave 13)
    Route::prefix('performance')->name('performance.')->middleware('permission:performance.view')->group(function () {
        // Goals
        Route::get('/goals',                       [PerformanceController::class, 'goals'])         ->name('goals.index');
        Route::post('/goals',                      [PerformanceController::class, 'storeGoal'])     ->name('goals.store');
        Route::patch('/goals/{goal}',              [PerformanceController::class, 'updateGoal'])    ->name('goals.update');
        Route::delete('/goals/{goal}',             [PerformanceController::class, 'destroyGoal'])   ->name('goals.destroy');
        Route::post('/goals/{goal}/checkins',      [PerformanceController::class, 'storeCheckin'])  ->name('goals.checkins.store');

        // Reviews
        Route::get('/reviews',                     [PerformanceController::class, 'reviews'])           ->name('reviews.index');
        Route::post('/reviews',                    [PerformanceController::class, 'storeReview'])       ->name('reviews.store');
        Route::patch('/reviews/{review}/submit',   [PerformanceController::class, 'submitReview'])      ->name('reviews.submit');
        Route::patch('/reviews/{review}/ack',      [PerformanceController::class, 'acknowledgeReview']) ->name('reviews.acknowledge');
        // Short alias — preserved for tests + older client-side route() helpers
        Route::patch('/reviews/{review}/acknowledge', [PerformanceController::class, 'acknowledgeReview'])->name('reviews.ack');

        // Cycles (HR-managed)
        Route::post('/cycles',                     [PerformanceController::class, 'storeCycle'])
            ->middleware('permission:performance.manage')
            ->name('cycles.store');
        Route::patch('/cycles/{cycle}/close',      [PerformanceController::class, 'closeCycle'])
            ->middleware('permission:performance.manage')
            ->name('cycles.close');

        // 9-Box (calibration)
        Route::get('/nine-box',                    [PerformanceController::class, 'nineBox'])
            ->middleware('permission:performance.manage')
            ->name('nine-box');
    });

    // Learning & Development — Catalog, MyLearning, Certifications, SkillsMatrix (Wave 13)
    Route::prefix('learning')->name('learning.')->middleware('permission:learning.view')->group(function () {
        // Catalogue (anyone with learning.view)
        Route::get('/',                         [LearningController::class, 'catalog'])    ->name('catalog');
        Route::get('/my',                       [LearningController::class, 'myLearning']) ->name('my');
        Route::get('/skills-matrix',            [LearningController::class, 'skillsMatrix'])
            ->middleware('permission:learning.manage')->name('skills-matrix');
        Route::post('/skills',                  [LearningController::class, 'storeSkill'])
            ->middleware('permission:learning.manage')->name('skills.store');

        // Course management (HR/LD)
        Route::post('/courses',                 [LearningController::class, 'storeCourse'])
            ->middleware('permission:learning.manage')->name('courses.store');
        Route::patch('/courses/{course}',       [LearningController::class, 'updateCourse'])
            ->middleware('permission:learning.manage')->name('courses.update');
        Route::patch('/courses/{course}/publish',[LearningController::class, 'publishCourse'])
            ->middleware('permission:learning.manage')->name('courses.publish');
        Route::delete('/courses/{course}',      [LearningController::class, 'destroyCourse'])
            ->middleware('permission:learning.manage')->name('courses.destroy');

        // Enrolment + progress
        Route::post('/courses/{course}/enrol',  [LearningController::class, 'enrol'])         ->name('courses.enrol');
        Route::patch('/enrolments/{enrolment}', [LearningController::class, 'recordProgress'])->name('enrolments.progress');

        // Certifications
        Route::post('/certifications',          [LearningController::class, 'storeCertification'])->name('certifications.store');

        // Compliance requirements (HR/LD) — dashboard + create + auto-sync mandatory training
        Route::get('compliance',                [\App\Http\Controllers\Learning\ComplianceController::class, 'index'])
            ->middleware('permission:learning.compliance.manage')->name('compliance.index');
        Route::post('compliance',               [\App\Http\Controllers\Learning\ComplianceController::class, 'store'])
            ->middleware('permission:learning.compliance.manage')->name('compliance.store');
    });

    // Payroll
    Route::prefix('payments')->name('payments.')->middleware('permission:payroll.manage')->group(function () {
        Route::get('/',                  [PaymentController::class, 'index'])         ->name('index');
        Route::post('/',                 [PaymentController::class, 'store'])         ->name('store');
        Route::post('/payslip/preview',  [PaymentController::class, 'previewPayslip'])->name('payslip.preview');
        Route::post('/payslip/generate', [PaymentController::class, 'generatePayslip'])->name('payslip.generate');
        Route::get('{payment}',          [PaymentController::class, 'show'])          ->name('show');
        Route::patch('{payment}/paid',   [PaymentController::class, 'markPaid'])      ->name('paid');
    });

    // Audit logs
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('permission:audit.view')
        ->name('audit-logs.index');

    // Reports
    Route::prefix('reports')->name('reports.')->middleware('permission:reports.view')->group(function () {
        Route::get('/',        [ReportsController::class, 'index'])  ->name('index');
        Route::get('/export',  [ReportsController::class, 'export']) ->name('export');
    });

    // Workforce analytics dashboard (KPIs + charts) — read-only
    Route::get('analytics/workforce', [WorkforceAnalyticsController::class, 'index'])
        ->name('analytics.workforce')
        ->middleware('permission:workforce.analytics.view');

    // Announcements / Notice ticker
    Route::prefix('announcements')->name('announcements.')->group(function () {
        Route::get('/',                  [AnnouncementController::class, 'index'])
            ->middleware('permission:announcements.manage')->name('index');
        Route::post('/',                 [AnnouncementController::class, 'store'])
            ->middleware('permission:announcements.manage')->name('store');
        Route::patch('{announcement}',   [AnnouncementController::class, 'update'])
            ->middleware('permission:announcements.manage')->name('update');
        Route::delete('{announcement}',  [AnnouncementController::class, 'destroy'])
            ->middleware('permission:announcements.manage')->name('destroy');
    });

    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/',          [NotificationController::class, 'index'])         ->name('index');
        Route::post('/read-all', [NotificationController::class, 'readAll'])       ->name('readAll');
        Route::get('/channels',  [NotificationChannelController::class, 'edit'])   ->name('channels.edit');
        Route::patch('/channels',[NotificationChannelController::class, 'update']) ->name('channels.update');
    });

    // AI assistant
    // Per-call provider cost makes this an abuse vector — gate by ai.use and
    // throttle 30/min/user (audit-v2 tier-3 supplement, item 28).
    Route::post('/ai/employee-summary', [AiAssistantController::class, 'summary'])
        ->middleware(['permission:ai.use', 'throttle:30,1'])
        ->name('ai.employee-summary');

    // ── Internal chat (employee-to-employee messaging) ──
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/',                          [\App\Http\Controllers\ChatController::class, 'index'])->name('index');
        Route::get('with/{other}',               [\App\Http\Controllers\ChatController::class, 'openWith'])->name('openWith');
        Route::get('{conversation}',             [\App\Http\Controllers\ChatController::class, 'show'])->name('show');
        Route::post('{conversation}/messages',   [\App\Http\Controllers\ChatController::class, 'send'])->name('send');
        Route::get('{conversation}/poll',        [\App\Http\Controllers\ChatController::class, 'poll'])->name('poll');
        Route::delete('messages/{message}',      [\App\Http\Controllers\ChatController::class, 'destroyMessage'])->name('messages.destroy');
    });

    // ── Phase 1: Statutory Payroll Runs ──
    Route::prefix('payroll-runs')->name('payroll-runs.')->group(function () {
        Route::get('/',                       [PayrollRunController::class, 'index'])
            ->middleware('permission:payroll.view_all')->name('index');
        Route::post('/',                      [PayrollRunController::class, 'store'])
            ->middleware('permission:payroll.run')->name('store');
        Route::get('{run}',                   [PayrollRunController::class, 'show'])
            ->middleware('permission:payroll.view_all')->name('show');
        Route::get('{run}/payslip/{line}',    [PayrollRunController::class, 'payslip'])
            ->middleware('permission:payroll.view_all')->name('payslip');
        Route::post('{run}/calculate',        [PayrollRunController::class, 'calculate'])
            ->middleware('permission:payroll.run')->name('calculate');
        Route::post('{run}/approve',          [PayrollRunController::class, 'approve'])
            ->middleware(['permission:payroll.approve', '2fa:fresh'])->name('approve');
        Route::post('{run}/reverse',          [PayrollRunController::class, 'reverse'])
            ->middleware(['permission:payroll.reverse', '2fa:fresh'])->name('reverse');
        Route::post('{run}/mark-paid',        [PayrollRunController::class, 'markPaid'])
            ->middleware('permission:payroll.approve')->name('mark-paid');
        Route::get('{run}/returns/{returnId}',[PayrollRunController::class, 'downloadReturn'])
            ->middleware('permission:statutory.export')->name('return-download');
        Route::post('{run}/returns/{returnId}/mark-filed', [PayrollRunController::class, 'markReturnFiled'])
            ->middleware('permission:statutory.remit')->name('return-mark-filed');
        Route::get('{run}/ippd-export',       [PayrollRunController::class, 'downloadIppd'])
            ->middleware('permission:statutory.export')->name('ippd-export');
        Route::get('{run}/gifmis-export',     [PayrollRunController::class, 'downloadGifmis'])
            ->middleware('permission:statutory.export')->name('gifmis-export');
    });

    // Salary revisions (across-the-board % — writes new effective-dated grade rates)
    Route::prefix('salary-revisions')->name('salary-revisions.')->middleware('permission:payroll.run')->group(function () {
        Route::get('/',        [\App\Http\Controllers\SalaryRevisionController::class, 'index'])  ->name('index');
        Route::post('/preview',[\App\Http\Controllers\SalaryRevisionController::class, 'preview'])->name('preview');
        Route::post('/',       [\App\Http\Controllers\SalaryRevisionController::class, 'store'])  ->name('store');
        Route::get('/{revision}/back-pay', [\App\Http\Controllers\SalaryRevisionController::class, 'backPay'])->name('back-pay');
        Route::post('/{revision}/back-pay', [\App\Http\Controllers\BackPayRunController::class, 'store'])->name('back-pay.run');
    });

    Route::prefix('back-pay-runs')->name('back-pay-runs.')->middleware('permission:payroll.run')->group(function () {
        Route::get('/{run}',         [\App\Http\Controllers\BackPayRunController::class, 'show'])   ->name('show');
        Route::post('/{run}/approve',[\App\Http\Controllers\BackPayRunController::class, 'approve'])->name('approve');
        Route::post('/{run}/pay',    [\App\Http\Controllers\BackPayRunController::class, 'pay'])    ->name('pay');
    });

    // ── Phase 1: Establishment (Positions) ──
    Route::prefix('positions')->name('positions.')->group(function () {
        Route::get('/',                 [PositionController::class, 'index'])
            ->middleware('permission:positions.view')->name('index');
        Route::post('/',                [PositionController::class, 'store'])
            ->middleware('permission:positions.manage')->name('store');
        Route::get('{position}',        [PositionController::class, 'show'])
            ->middleware('permission:positions.view')->name('show');
        Route::post('{position}/assign',[PositionController::class, 'assign'])
            ->middleware('permission:positions.manage')->name('assign');
        Route::post('{position}/vacate',[PositionController::class, 'vacate'])
            ->middleware('permission:positions.manage')->name('vacate');
        Route::post('{position}/freeze',[PositionController::class, 'freeze'])
            ->middleware('permission:positions.manage')->name('freeze');
    });

    // ── Phase 1: Identity verification ──
    Route::prefix('identity')->name('identity.')->group(function () {
        Route::get('/',    [IdentityVerificationController::class, 'index'])
            ->middleware('permission:identity.view')->name('index');
        Route::post('/',   [IdentityVerificationController::class, 'store'])
            ->middleware('permission:identity.verify')->name('store');
    });

    // ── Phase 2: Time & Attendance ──
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/',          [AttendanceController::class, 'index'])
            ->middleware('permission:attendance.view')->name('index');
        Route::get('/me',        [AttendanceController::class, 'myAttendance'])
            ->name('me'); // any authenticated user with an employee record
        Route::post('/clock',    [AttendanceController::class, 'clockSelf'])
            ->middleware(['permission:attendance.clock_self', 'throttle:10,1'])->name('clock');
        Route::post('/manual',   [AttendanceController::class, 'manualEntry'])
            ->middleware('permission:attendance.manage')->name('manual');

        Route::prefix('shifts')->name('shifts.')->middleware('permission:attendance.shift_manage')->group(function () {
            Route::get('/',              [AttendanceController::class, 'shiftsIndex'])->name('index');
            Route::post('/',             [AttendanceController::class, 'storeShift'])->name('store');
            Route::patch('/{shift}',     [AttendanceController::class, 'updateShift'])->name('update');
            Route::delete('/{shift}',    [AttendanceController::class, 'destroyShift'])->name('destroy');
            Route::post('/assignments',  [AttendanceController::class, 'assignShift'])->name('assign');
        });

        Route::prefix('corrections')->name('corrections.')->group(function () {
            Route::get('/',  [AttendanceController::class, 'correctionsIndex'])
                ->middleware('permission:attendance.approve')->name('index');
            Route::post('/', [AttendanceController::class, 'storeCorrection'])
                ->middleware('permission:attendance.correct')->name('store');
            Route::patch('/{correction}/review', [AttendanceController::class, 'reviewCorrection'])
                ->middleware('permission:attendance.approve')->name('review');
        });
    });

    // ── Phase 2: Loans & Advances ──
    Route::prefix('loans')->name('loans.')->group(function () {
        Route::get('/',                   [LoanAccountController::class, 'index'])
            ->middleware('permission:loans.view')->name('index');
        Route::post('/',                  [LoanAccountController::class, 'store'])
            ->middleware('permission:loans.apply')->name('store');
        Route::get('{loan}',              [LoanAccountController::class, 'show'])
            ->middleware('permission:loans.view')->name('show');
        Route::post('{loan}/decide',      [LoanAccountController::class, 'decide'])
            ->middleware(['permission:loans.approve', '2fa:fresh'])->name('decide');
        Route::post('{loan}/disburse',    [LoanAccountController::class, 'disburse'])
            ->middleware(['permission:loans.disburse', '2fa:fresh'])->name('disburse');
        Route::post('preview',            [LoanAccountController::class, 'preview'])
            ->middleware('permission:loans.apply')->name('preview');

        // Loan product catalogue (HR / Finance with loans.product_manage)
        Route::prefix('products')->name('products.')->middleware('permission:loans.product_manage')->group(function () {
            Route::post('/',           [LoanAccountController::class, 'storeProduct'])->name('store');
            Route::patch('{product}',  [LoanAccountController::class, 'updateProduct'])->name('update');
            Route::delete('{product}', [LoanAccountController::class, 'destroyProduct'])->name('destroy');
        });
    });

    // ── Phase 2: Performance Management — Contracts / Calibration / PIPs ──
    Route::prefix('performance')->name('performance.')->group(function () {
        Route::prefix('contracts')->name('contracts.')->group(function () {
            Route::get('/',                    [PerformanceContractController::class, 'index'])
                ->middleware('permission:performance.view')->name('index');
            Route::post('/',                   [PerformanceContractController::class, 'store'])
                ->middleware('permission:performance.manage')->name('store');
            Route::get('{contract}',           [PerformanceContractController::class, 'show'])
                ->middleware('permission:performance.view')->name('show');
            Route::post('{contract}/send',     [PerformanceContractController::class, 'send'])
                ->middleware('permission:performance.manage')->name('send');
            Route::post('{contract}/revoke',   [PerformanceContractController::class, 'revoke'])
                ->middleware('permission:performance.manage')->name('revoke');
            Route::post('{contract}/sign',     [PerformanceContractController::class, 'sign'])->name('sign');
            Route::post('{contract}/evaluate', [PerformanceContractController::class, 'evaluate'])
                ->middleware(['permission:performance.manage', '2fa:fresh'])->name('evaluate');
        });

        Route::prefix('calibration')->name('calibration.')->group(function () {
            Route::get('/',                  [CalibrationController::class, 'index'])
                ->middleware('permission:performance.calibrate')->name('index');
            Route::post('/',                 [CalibrationController::class, 'store'])
                ->middleware('permission:performance.calibrate')->name('store');
            Route::get('{session}',          [CalibrationController::class, 'show'])
                ->middleware('permission:performance.calibrate')->name('show');
            Route::post('{session}/adjust',  [CalibrationController::class, 'adjust'])
                ->middleware('permission:performance.calibrate')->name('adjust');
            Route::post('{session}/lock',    [CalibrationController::class, 'lock'])
                ->middleware('permission:performance.calibrate')->name('lock');
            Route::post('{session}/apply',   [CalibrationController::class, 'apply'])
                ->middleware(['permission:performance.calibrate_apply', '2fa:fresh'])->name('apply');
            Route::post('{session}/reopen',  [CalibrationController::class, 'reopen'])
                ->middleware('permission:performance.calibrate')->name('reopen');
        });

        Route::prefix('pips')->name('pips.')->group(function () {
            Route::get('/',                  [PipController::class, 'index'])
                ->middleware('permission:performance.pip_manage')->name('index');
            Route::post('/',                 [PipController::class, 'store'])
                ->middleware('permission:performance.pip_manage')->name('store');
            Route::get('{pip}',              [PipController::class, 'show'])
                ->middleware('permission:performance.view')->name('show');
            Route::post('{pip}/checkins',    [PipController::class, 'checkin'])
                ->middleware('permission:performance.pip_manage')->name('checkin');
            Route::post('{pip}/extend',      [PipController::class, 'extend'])
                ->middleware('permission:performance.pip_manage')->name('extend');
            Route::post('{pip}/close',       [PipController::class, 'close'])
                ->middleware(['permission:performance.pip_manage', '2fa:fresh'])->name('close');
        });
    });

    // ── Phase 3: DPA 2012 Data-Subject Portal ──
    // Subject self-service (any authenticated user)
    Route::prefix('privacy')->name('privacy.')->group(function () {
        Route::get('/',                          [PrivacyController::class, 'myRequests'])->name('my');
        Route::post('/',                         [PrivacyController::class, 'submit'])->name('submit');
        Route::post('{req}/withdraw',            [PrivacyController::class, 'withdraw'])->name('withdraw');
        Route::get('{req}/download',             [PrivacyController::class, 'downloadMyExport'])->name('download');
    });

    // DPO admin queue
    Route::prefix('admin/privacy')->name('privacy.admin.')->middleware('permission:privacy.fulfill')->group(function () {
        Route::get('/',                          [PrivacyController::class, 'adminIndex'])->name('index');
        Route::get('{req}',                      [PrivacyController::class, 'adminShow'])->name('show');
        Route::post('{req}/acknowledge',         [PrivacyController::class, 'acknowledge'])->name('acknowledge');
        Route::post('{req}/fulfill',             [PrivacyController::class, 'fulfill'])
            ->middleware('2fa:fresh')->name('fulfill');
        Route::post('{req}/reject',              [PrivacyController::class, 'reject'])->name('reject');
    });

    // ── Phase 3: Disbursements (MoMo + GhIPSS) ──
    Route::prefix('disbursements')->name('disbursements.')->group(function () {
        Route::get('/',                          [DisbursementController::class, 'index'])
            ->middleware('permission:payroll.view_all')->name('index');
        Route::post('runs/{run}/dispatch',       [DisbursementController::class, 'dispatchRun'])
            ->middleware(['permission:payroll.disburse', '2fa:fresh'])->name('dispatch');
        Route::post('runs/{run}/reconcile',      [DisbursementController::class, 'reconcile'])
            ->middleware('permission:payroll.disburse')->name('reconcile');
        Route::post('runs/{run}/confirm-ghipss', [DisbursementController::class, 'confirmGhipss'])
            ->middleware('permission:payroll.disburse')->name('confirm-ghipss');
    });

    // ── Phase 3: SMS / USSD message log (WS18) ──
    Route::prefix('admin/messaging')->name('messaging.')->group(function () {
        Route::get('/',           [MessagingController::class, 'index'])
            ->middleware('permission:messaging.view')->name('index');
        Route::post('/send',      [MessagingController::class, 'send'])
            ->middleware('permission:messaging.send')->name('send');
        Route::post('/pins',      [MessagingController::class, 'issuePin'])
            ->middleware(['permission:messaging.manage', '2fa:fresh'])->name('pins.issue');

        // ── N3 Broadcasts ──
        Route::get('broadcasts',                [\App\Http\Controllers\BroadcastController::class, 'index'])
            ->middleware('permission:broadcasts.view')->name('broadcasts.index');
        Route::get('broadcasts/create',         [\App\Http\Controllers\BroadcastController::class, 'create'])
            ->middleware('permission:broadcasts.manage')->name('broadcasts.create');
        Route::post('broadcasts',               [\App\Http\Controllers\BroadcastController::class, 'store'])
            ->middleware('permission:broadcasts.manage')->name('broadcasts.store');
        Route::get('broadcasts/{broadcast}',    [\App\Http\Controllers\BroadcastController::class, 'show'])
            ->middleware('permission:broadcasts.view')->name('broadcasts.show');
        Route::post('broadcasts/{broadcast}/cancel', [\App\Http\Controllers\BroadcastController::class, 'cancel'])
            ->middleware('permission:broadcasts.manage')->name('broadcasts.cancel');
        Route::post('broadcasts/preview',       [\App\Http\Controllers\BroadcastController::class, 'preview'])
            ->middleware('permission:broadcasts.manage')->name('broadcasts.preview');

        // ── N3 Broadcast Templates ──
        Route::get('templates',              [\App\Http\Controllers\BroadcastTemplateController::class, 'index'])
            ->middleware('permission:broadcasts.view')->name('templates.index');
        Route::post('templates',             [\App\Http\Controllers\BroadcastTemplateController::class, 'store'])
            ->middleware('permission:broadcasts.manage')->name('templates.store');
        Route::patch('templates/{template}', [\App\Http\Controllers\BroadcastTemplateController::class, 'update'])
            ->middleware('permission:broadcasts.manage')->name('templates.update');
        Route::delete('templates/{template}',[\App\Http\Controllers\BroadcastTemplateController::class, 'destroy'])
            ->middleware('permission:broadcasts.manage')->name('templates.destroy');
    });

    // ── Phase 3: API token + webhook admin (WS16) ──
    Route::prefix('admin/api-tokens')->name('api-tokens.')->middleware('permission:api.token_manage')->group(function () {
        Route::get('/',           [ApiTokenController::class, 'index'])  ->name('index');
        Route::post('/',          [ApiTokenController::class, 'store'])  ->name('store');
        Route::delete('{token}',  [ApiTokenController::class, 'destroy'])->name('destroy');
    });
    Route::prefix('admin/webhooks')->name('webhooks.')->middleware('permission:api.webhooks_manage')->group(function () {
        Route::get('/',                  [WebhooksController::class, 'index'])  ->name('index');
        Route::post('/',                 [WebhooksController::class, 'store'])  ->name('store');
        Route::patch('{subscription}',   [WebhooksController::class, 'update']) ->name('update');
        Route::delete('{subscription}',  [WebhooksController::class, 'destroy'])->name('destroy');
    });

    // ── Phase 2: Auditor-General Report Pack ──
    Route::prefix('reports/auditor-general')->name('ag-reports.')->group(function () {
        Route::get('/',                       [AuditorGeneralReportController::class, 'index'])
            ->middleware('permission:reports.view')->name('index');
        Route::post('/',                      [AuditorGeneralReportController::class, 'generate'])
            ->middleware(['permission:statutory.export', '2fa:fresh'])->name('generate');
        Route::get('download/{filename}',     [AuditorGeneralReportController::class, 'download'])
            ->middleware('permission:statutory.export')->name('download');
    });

    // ── Phase 2: Whistleblower (investigator dashboard) ──
    // Public submission/tracking lives outside this auth group above.
    Route::prefix('admin/whistleblower')->name('whistleblower.admin.')->group(function () {
        Route::get('/',                  [WhistleblowerAdminController::class, 'index'])
            ->middleware('permission:whistleblower.investigate')->name('index');
        Route::get('{report}',           [WhistleblowerAdminController::class, 'show'])
            ->middleware('permission:whistleblower.investigate')->name('show');
        Route::post('{report}/triage',   [WhistleblowerAdminController::class, 'triage'])
            ->middleware(['permission:whistleblower.investigate', '2fa:fresh'])->name('triage');
        Route::post('{report}/actions',  [WhistleblowerAdminController::class, 'logAction'])
            ->middleware('permission:whistleblower.investigate')->name('actions');
        Route::post('{report}/messages', [WhistleblowerAdminController::class, 'postMessage'])
            ->middleware('permission:whistleblower.investigate')->name('messages');
        Route::post('{report}/assign',   [WhistleblowerAdminController::class, 'assign'])
            ->middleware(['permission:whistleblower.manage', '2fa:fresh'])->name('assign');
    });

    // ── Phase 2: Off-boarding & Final Settlement ──
    Route::prefix('offboarding')->name('offboarding.')->group(function () {
        Route::get('/',                                    [OffboardingController::class, 'index'])
            ->middleware('permission:offboarding.view')->name('index');
        Route::post('/',                                   [OffboardingController::class, 'store'])
            ->middleware('permission:offboarding.initiate')->name('store');
        Route::get('{case}',                               [OffboardingController::class, 'show'])
            ->middleware('permission:offboarding.view')->name('show');
        Route::post('{case}/clearance/{item}',             [OffboardingController::class, 'clearItem'])
            ->middleware('permission:offboarding.clear')->name('clearance.update');
        Route::post('{case}/settlement/calculate',         [OffboardingController::class, 'calculateSettlement'])
            ->middleware('permission:offboarding.settle')->name('settlement.calculate');
        Route::post('{case}/settlement/approve',           [OffboardingController::class, 'approveSettlement'])
            ->middleware(['permission:offboarding.approve', '2fa:fresh'])->name('settlement.approve');
        Route::post('{case}/settlement/pay',               [OffboardingController::class, 'paySettlement'])
            ->middleware(['permission:offboarding.approve', '2fa:fresh'])->name('settlement.pay');
        Route::post('{case}/settlement/reverse',           [OffboardingController::class, 'reverseSettlement'])
            ->middleware(['permission:offboarding.approve', '2fa:fresh'])->name('settlement.reverse');
        Route::post('{case}/settlement/dispatch-payout',   [OffboardingController::class, 'dispatchPayout'])
            ->middleware(['permission:offboarding.approve', '2fa:fresh'])->name('settlement.dispatch-payout');
        Route::post('{case}/complete',                     [OffboardingController::class, 'complete'])
            ->middleware(['permission:offboarding.manage', '2fa:fresh'])->name('complete');
        Route::post('{case}/cancel',                       [OffboardingController::class, 'cancel'])
            ->middleware('permission:offboarding.manage')->name('cancel');
    });

    // ── Onboarding Lifecycle ──
    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::get('/',                       [\App\Http\Controllers\OnboardingController::class, 'index'])
            ->middleware('permission:onboarding.view')->name('index');
        Route::post('/',                      [\App\Http\Controllers\OnboardingController::class, 'store'])
            ->middleware('permission:onboarding.initiate')->name('store');
        Route::get('{case}',                  [\App\Http\Controllers\OnboardingController::class, 'show'])
            ->middleware('permission:onboarding.view')->name('show');
        Route::post('{case}/tasks/{task}',    [\App\Http\Controllers\OnboardingController::class, 'updateTask'])
            ->middleware('permission:onboarding.complete')->name('tasks.update');
        Route::post('{case}/complete',        [\App\Http\Controllers\OnboardingController::class, 'complete'])
            ->middleware('permission:onboarding.complete')->name('complete');
        Route::post('{case}/cancel',          [\App\Http\Controllers\OnboardingController::class, 'cancel'])
            ->middleware('permission:onboarding.manage')->name('cancel');
    });

    // ── Phase 3: Assets ──
    Route::prefix('assets')->name('assets.')->group(function () {
        Route::get('/',                              [AssetController::class, 'index'])
            ->middleware('permission:assets.view')->name('index');
        Route::post('/',                             [AssetController::class, 'store'])
            ->middleware('permission:assets.manage')->name('store');
        Route::get('/my',                            [AssetController::class, 'myAssets'])->name('my');
        Route::get('/{asset}',                       [AssetController::class, 'show'])
            ->middleware('permission:assets.view')->name('show');
        Route::patch('/{asset}',                     [AssetController::class, 'update'])
            ->middleware('permission:assets.manage')->name('update');
        Route::delete('/{asset}',                    [AssetController::class, 'destroy'])
            ->middleware('permission:assets.manage')->name('destroy');
        Route::post('/{asset}/assign',               [AssetController::class, 'assign'])->name('assign');
        Route::post('/assignments/{assignment}/return',   [AssetController::class, 'returnAsset'])->name('return');
        Route::post('/{asset}/maintenance',          [AssetController::class, 'storeMaintenance'])
            ->middleware('permission:assets.manage')->name('maintenance.store');
        Route::patch('/maintenance/{maintenance}/complete', [AssetController::class, 'completeMaintenance'])
            ->middleware('permission:assets.manage')->name('maintenance.complete');
        Route::patch('/{asset}/retire',              [AssetController::class, 'retire'])
            ->middleware('permission:assets.manage')->name('retire');
        Route::patch('/{asset}/lost',                [AssetController::class, 'markLost'])
            ->middleware('permission:assets.manage')->name('lost');
    });

    // ── Phase 4: Benefits ──
    Route::prefix('benefits')->name('benefits.')->group(function () {
        Route::get('/',                       [BenefitsController::class, 'index'])->name('index');

        Route::prefix('plans')->name('plans.')->middleware('permission:benefits.manage')->group(function () {
            Route::get('/',                   [BenefitsController::class, 'plansIndex'])->name('index');
            Route::post('/',                  [BenefitsController::class, 'storePlan'])->name('store');
            Route::patch('/{plan}',           [BenefitsController::class, 'updatePlan'])->name('update');
            Route::delete('/{plan}',          [BenefitsController::class, 'destroyPlan'])->name('destroy');
        });

        Route::post('/enrol',                 [BenefitsController::class, 'enrol'])
            ->middleware('permission:benefits.enrol')->name('enrol');

        Route::prefix('dependants')->name('dependants.')->group(function () {
            Route::post('/',                  [BenefitsController::class, 'storeDependant'])
                ->middleware('permission:benefits.enrol')->name('store');
            Route::patch('/{dependant}',      [BenefitsController::class, 'updateDependant'])
                ->middleware('permission:benefits.enrol')->name('update');
            Route::delete('/{dependant}',     [BenefitsController::class, 'destroyDependant'])
                ->middleware('permission:benefits.enrol')->name('destroy');
        });

        Route::prefix('claims')->name('claims.')->group(function () {
            Route::get('/',                   [BenefitsController::class, 'claimsIndex'])
                ->middleware('permission:benefits.manage')->name('index');
            Route::post('/',                  [BenefitsController::class, 'submitClaim'])
                ->middleware('permission:benefits.claim')->name('store');
            Route::patch('/{claim}/withdraw', [BenefitsController::class, 'withdrawClaim'])
                ->middleware('permission:benefits.claim')->name('withdraw');
            Route::patch('/{claim}/decide',   [BenefitsController::class, 'decideClaim'])
                ->middleware('permission:benefits.manage')->name('decide');
        });

        Route::get('/enrolments/{enrolment}/e-card', [BenefitsController::class, 'downloadECard'])->name('e-card');
    });

    // ── Phase 5: Governance ──
    Route::prefix('governance')->name('governance.')->group(function () {
        Route::get('/',                            [GovernanceController::class, 'index'])
            ->middleware('permission:governance.view')->name('index');
        Route::get('/manage',                      [GovernanceController::class, 'manage'])
            ->middleware('permission:governance.manage')->name('manage');
        Route::get('/policies/{policy}',           [GovernanceController::class, 'showPolicy'])
            ->middleware('permission:governance.view')->name('policies.show');
        Route::post('/policies',                   [GovernanceController::class, 'storePolicy'])
            ->middleware('permission:governance.manage')->name('policies.store');
        Route::patch('/policies/{policy}',         [GovernanceController::class, 'updatePolicy'])
            ->middleware('permission:governance.manage')->name('policies.update');
        Route::post('/policies/{policy}/versions', [GovernanceController::class, 'addVersion'])
            ->middleware('permission:governance.manage')->name('policies.versions.store');
        Route::patch('/versions/{version}/publish',[GovernanceController::class, 'publishVersion'])
            ->middleware('permission:governance.manage')->name('versions.publish');
        Route::post('/versions/{version}/ack',     [GovernanceController::class, 'acknowledge'])
            ->middleware('permission:governance.acknowledge')->name('versions.ack');

        Route::prefix('certifications')->name('certifications.')->group(function () {
            Route::get('/',                        [GovernanceController::class, 'certificationsIndex'])->name('index');
            Route::post('/',                       [GovernanceController::class, 'storeCertification'])
                ->middleware('permission:governance.cert_manage')->name('store');
            Route::patch('/{certification}',       [GovernanceController::class, 'updateCertification'])
                ->middleware('permission:governance.cert_manage')->name('update');
            Route::delete('/{certification}',      [GovernanceController::class, 'destroyCertification'])
                ->middleware('permission:governance.cert_manage')->name('destroy');
            Route::post('/dispatch-reminders',     [GovernanceController::class, 'dispatchReminders'])
                ->middleware('permission:governance.cert_manage')->name('dispatch-reminders');
        });
    });

    // ── Phase 5: Incident Reporting ──
    // Submitter-driven routes (store / update / messages.store / and even
    // assign+unassign — the submitter is allowed to pick/swap a reviewer on
    // their own report per IncidentReportPolicy) remain policy-gated only.
    // The genuinely reviewer-only mutations (close / reopen) get
    // defence-in-depth `incidents.review` middleware in addition to the policy
    // (audit-v2 tier-3 supplement, item 29) so a future refactor that drops
    // $this->authorize() in the controller still leaves the route closed.
    Route::prefix('governance/incidents')->name('incidents.')->group(function () {
        Route::get('/',                                  [\App\Http\Controllers\IncidentReportController::class, 'index'])    ->name('index');
        Route::get('/{report}',                          [\App\Http\Controllers\IncidentReportController::class, 'show'])     ->name('show');
        Route::post('/',                                 [\App\Http\Controllers\IncidentReportController::class, 'store'])    ->name('store');
        Route::patch('/{report}',                        [\App\Http\Controllers\IncidentReportController::class, 'update'])   ->name('update');
        Route::post('/{report}/assign',                  [\App\Http\Controllers\IncidentReportController::class, 'assign'])   ->name('assign');
        Route::delete('/{report}/assign/{user}',         [\App\Http\Controllers\IncidentReportController::class, 'unassign']) ->name('unassign');
        Route::post('/{report}/messages',                [\App\Http\Controllers\IncidentReportController::class, 'postMessage'])->name('messages.store');
        Route::post('/{report}/close',                   [\App\Http\Controllers\IncidentReportController::class, 'close'])
            ->middleware('permission:incidents.review')->name('close');
        Route::post('/{report}/reopen',                  [\App\Http\Controllers\IncidentReportController::class, 'reopen'])
            ->middleware('permission:incidents.review')->name('reopen');
        Route::get('/attachments/{attachment}/download', [\App\Http\Controllers\IncidentReportController::class, 'downloadAttachment'])->name('attachments.download');
    });

    // ── Phase 1: Two-factor auth ──
    Route::prefix('two-factor')->name('two-factor.')->group(function () {
        Route::get('/enroll',     [TwoFactorController::class, 'enroll'])        ->name('enroll');
        Route::post('/enroll',    [TwoFactorController::class, 'confirm'])       ->name('confirm');
        Route::get('/challenge',  [TwoFactorController::class, 'challengeForm']) ->name('challenge');
        Route::post('/challenge', [TwoFactorController::class, 'challenge'])     ->name('challenge.submit');
        Route::delete('/',        [TwoFactorController::class, 'disable'])       ->name('disable');
    });

    // Admin → User management (create super_admin / CEO / role users)
    Route::prefix('admin/users')->name('admin.users.')->middleware('permission:users.manage')->group(function () {
        Route::get('/',             [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('index');
        Route::post('/',            [\App\Http\Controllers\Admin\UserController::class, 'store'])->name('store');
        // Live ID preview for the New User form — non-mutating, peeks at the
        // sequence so the operator sees what staff_id/employee_no would be
        // assigned without burning a counter value.
        Route::get('/preview-ids',  [\App\Http\Controllers\Admin\UserController::class, 'previewIds'])->name('preview-ids');
    });

    // Admin → Integrations marketplace + OAuth callbacks
    Route::prefix('admin/integrations')->name('admin.integrations.')->middleware('permission:integrations.manage')->group(function () {
        Route::get('/',                              [IntegrationController::class, 'index'])     ->name('index');
        Route::post('/{provider}/connect',           [IntegrationController::class, 'connect'])    ->name('connect');
        Route::delete('/{provider}',                 [IntegrationController::class, 'disconnect']) ->name('disconnect');
    });
    // OAuth callback URLs are stable — drivers register them as their redirect_uri.
    Route::get('/admin/integrations/{provider}/callback', [IntegrationController::class, 'callback'])
        ->middleware('permission:integrations.manage')
        ->name('admin.integrations.callback');

    // Documents
    Route::prefix('documents')->name('documents.')->group(function () {
        Route::get('/',                            [DocumentController::class, 'index'])->name('index');
        Route::post('/',                           [DocumentController::class, 'store'])->name('store');
        // Static segments must be registered BEFORE `/{document}` so they
        // don't collide with the UUID-bound parameter route.
        Route::get('/users/search',                [DocumentController::class, 'searchUsers'])->name('users.search');
        // In-portal composer (HTML → PDF with optional institutional letterhead).
        Route::get('/compose',                     [DocumentController::class, 'compose'])->name('compose');
        Route::post('/compose',                    [DocumentController::class, 'storeComposed'])->name('compose.store');
        Route::get('/{document}',                  [DocumentController::class, 'show'])->name('show');
        Route::post('/{document}/versions',        [DocumentController::class, 'addVersion'])->name('versions.store');
        Route::post('/{document}/route',           [DocumentController::class, 'route'])->name('route');
        Route::post('/{document}/withdraw',        [DocumentController::class, 'withdraw'])->name('withdraw');
        Route::post('/{document}/archive',         [DocumentController::class, 'archive'])->name('archive');
        Route::post('/{document}/annotations',     [DocumentController::class, 'annotate'])->name('annotations.store');
        Route::delete('/{document}/annotations/{annotationId}', [DocumentController::class, 'removeAnnotation'])->name('annotations.destroy');
        Route::patch('/{document}/annotations/{annotation}', [DocumentController::class, 'updateAnnotation'])->name('annotations.update');
        Route::post('/{document}/routes/{route}/act', [DocumentController::class, 'act'])->name('routes.act');
        // `download` requires a valid signature (URL::temporarySignedRoute) —
        // the show page mints fresh 5-min URLs and the user follows those.
        // This stops anyone from sharing a permanent direct-download link.
        Route::get('/{document}/download',         [DocumentController::class, 'download'])
            ->middleware('signed')
            ->name('download');
        Route::post('/{document}/convert',         [DocumentController::class, 'convert'])->name('convert');

        // Documents v2 — Phase 1: edit / delete / share
        Route::patch('/{document}',                [DocumentController::class, 'update'])->name('update');
        Route::delete('/{document}',               [DocumentController::class, 'destroy'])->name('destroy');
        Route::post('/{document}/shares',          [\App\Http\Controllers\DocumentShareController::class, 'store'])->name('shares.store');
        Route::delete('/{document}/shares/{share}', [\App\Http\Controllers\DocumentShareController::class, 'destroy'])->name('shares.destroy');
    });

    // Documents v2 — Phase 3: Stamp assets (Settings)
    Route::prefix('settings/stamps')->name('settings.stamps.')->group(function () {
        Route::get('/',                  [\App\Http\Controllers\Settings\StampAssetController::class, 'index'])->name('index');
        Route::post('/',                 [\App\Http\Controllers\Settings\StampAssetController::class, 'store'])->name('store');
        Route::get('/{asset}/preview',   [\App\Http\Controllers\Settings\StampAssetController::class, 'preview'])->name('preview');
        Route::delete('/{asset}',        [\App\Http\Controllers\Settings\StampAssetController::class, 'destroy'])->name('destroy');
    });

    // Documents v2 — Phase 4: Letterhead templates (Settings)
    Route::prefix('settings/letterheads')->name('settings.letterheads.')->group(function () {
        Route::get('/',                   [\App\Http\Controllers\Settings\LetterheadTemplateController::class, 'index'])->name('index');
        Route::post('/',                  [\App\Http\Controllers\Settings\LetterheadTemplateController::class, 'store'])->name('store');
        Route::get('/{template}/preview', [\App\Http\Controllers\Settings\LetterheadTemplateController::class, 'preview'])->name('preview');
        Route::delete('/{template}',      [\App\Http\Controllers\Settings\LetterheadTemplateController::class, 'destroy'])->name('destroy');
    });

    // Documents v2 — Phase 5: Watermark templates (Settings)
    Route::prefix('settings/watermarks')->name('settings.watermarks.')->group(function () {
        Route::get('/',                   [\App\Http\Controllers\Settings\WatermarkTemplateController::class, 'index'])->name('index');
        Route::post('/',                  [\App\Http\Controllers\Settings\WatermarkTemplateController::class, 'store'])->name('store');
        Route::get('/{template}/preview', [\App\Http\Controllers\Settings\WatermarkTemplateController::class, 'preview'])->name('preview');
        Route::delete('/{template}',      [\App\Http\Controllers\Settings\WatermarkTemplateController::class, 'destroy'])->name('destroy');
    });

    // ── F1: Finance ─────────────────────────────────────────────────────────
    // finance.hub gates ONLY the Finance Hub landing page.
    // Auditors who have accounts.view / bank_accounts.view but NOT finance.hub
    // must still reach the list endpoints, so each resource group carries its
    // own per-permission middleware.
    Route::prefix('finance')->name('finance.')->group(function () {
        // FinanceHubController wired in Task 9
        Route::middleware('permission:finance.hub')->group(function () {
            Route::get('/', [FinanceHubController::class, 'index'])->name('hub');
        });

        Route::middleware('permission:accounts.view')->group(function () {
            Route::get('accounts', [ChartOfAccountsController::class, 'index'])->name('accounts.index');
        });
        Route::middleware('permission:accounts.manage')->group(function () {
            Route::post('accounts',             [ChartOfAccountsController::class, 'store'])->name('accounts.store');
            Route::patch('accounts/{account}',  [ChartOfAccountsController::class, 'update'])->name('accounts.update');
            Route::delete('accounts/{account}', [ChartOfAccountsController::class, 'destroy'])->name('accounts.destroy');
        });

        // Universal Posting — account-determination map
        Route::middleware('permission:finance.posting_rules.manage')->group(function () {
            Route::get('posting-rules',                       [\App\Http\Controllers\Finance\PostingRuleController::class, 'index'])->name('posting-rules.index');
            Route::patch('posting-rules/{postingAccount}',    [\App\Http\Controllers\Finance\PostingRuleController::class, 'update'])->name('posting-rules.update');
        });

        // Fiscal periods (Phase 2)
        Route::middleware('permission:finance.period.view')->group(function () {
            Route::get('periods', [\App\Http\Controllers\Finance\PeriodController::class, 'index'])->name('periods.index');
        });
        Route::middleware(['permission:finance.period.close', '2fa:fresh'])->group(function () {
            Route::post('periods/{fiscalPeriod}/close', [\App\Http\Controllers\Finance\PeriodController::class, 'close'])->name('periods.close');
        });
        Route::middleware(['permission:finance.period.reopen', '2fa:fresh'])->group(function () {
            Route::post('periods/{fiscalPeriod}/reopen', [\App\Http\Controllers\Finance\PeriodController::class, 'reopen'])->name('periods.reopen');
        });
        Route::middleware(['permission:finance.period.lock', '2fa:fresh'])->group(function () {
            Route::post('periods/{fiscalPeriod}/lock', [\App\Http\Controllers\Finance\PeriodController::class, 'lock'])->name('periods.lock');
        });

        // Financial statements (Phase 3) — read-only
        Route::middleware('permission:finance.reports.view')->group(function () {
            Route::get('reports/trial-balance',            [\App\Http\Controllers\Finance\ReportController::class, 'trialBalance'])->name('reports.trial-balance');
            Route::get('reports/trial-balance/export.csv', [\App\Http\Controllers\Finance\ReportController::class, 'trialBalanceCsv'])->name('reports.trial-balance.csv');
            Route::get('reports/trial-balance/export.pdf', [\App\Http\Controllers\Finance\ReportController::class, 'trialBalancePdf'])->name('reports.trial-balance.pdf');
            Route::get('reports/financial-activities',             [\App\Http\Controllers\Finance\ReportController::class, 'financialActivities'])->name('reports.financial-activities');
            Route::get('reports/financial-activities/export.csv',  [\App\Http\Controllers\Finance\ReportController::class, 'financialActivitiesCsv'])->name('reports.financial-activities.csv');
            Route::get('reports/financial-position',               [\App\Http\Controllers\Finance\ReportController::class, 'financialPosition'])->name('reports.financial-position');
            Route::get('reports/account/{account}/ledger',         [\App\Http\Controllers\Finance\ReportController::class, 'accountLedger'])->name('reports.account-ledger');
            Route::get('reports/cash-flow',            [\App\Http\Controllers\Finance\ReportController::class, 'cashFlow'])->name('reports.cash-flow');
            Route::get('reports/cash-flow/export.csv', [\App\Http\Controllers\Finance\ReportController::class, 'cashFlowCsv'])->name('reports.cash-flow.csv');
            Route::get('reports/budget-vs-actuals',             [\App\Http\Controllers\Finance\ReportController::class, 'budgetVsActuals'])->name('reports.budget-vs-actuals');
            Route::get('reports/budget-vs-actuals/export.csv',  [\App\Http\Controllers\Finance\ReportController::class, 'budgetVsActualsCsv'])->name('reports.budget-vs-actuals.csv');
            Route::get('reports/budget-vs-actuals/export.pdf',  [\App\Http\Controllers\Finance\ReportController::class, 'budgetVsActualsPdf'])->name('reports.budget-vs-actuals.pdf');
        });

        // Website collections reconciliation dashboard (Website Finance Integration — Task 8)
        Route::get('/reconciliation-dashboard', [\App\Http\Controllers\Finance\CollectionReconciliationController::class, 'index'])
            ->middleware('permission:finance.reports.view')->name('reconciliation');

        // Analytics dashboard (KPIs + charts) — read-only
        Route::middleware('permission:finance.analytics.view')->group(function () {
            Route::get('analytics',             [\App\Http\Controllers\Finance\AnalyticsController::class, 'dashboard'])->name('analytics');
            Route::get('analytics/export.csv',  [\App\Http\Controllers\Finance\AnalyticsController::class, 'exportCsv'])->name('analytics.csv');
            Route::get('analytics/export.pdf',  [\App\Http\Controllers\Finance\AnalyticsController::class, 'exportPdf'])->name('analytics.pdf');
        });

        // Budgets (Phase 4) — entry/approval
        Route::middleware('permission:finance.budget.manage')->group(function () {
            Route::get('budgets',          [\App\Http\Controllers\Finance\BudgetController::class, 'index'])->name('budgets.index');
            Route::post('budgets/line',    [\App\Http\Controllers\Finance\BudgetController::class, 'storeLine'])->name('budgets.line');
            Route::post('budgets/approve', [\App\Http\Controllers\Finance\BudgetController::class, 'approve'])->name('budgets.approve');
            Route::post('budgets/revert',  [\App\Http\Controllers\Finance\BudgetController::class, 'revert'])->name('budgets.revert');
        });

        // OrgBankAccountController wired in Task 8
        Route::middleware('permission:bank_accounts.view')->group(function () {
            Route::get('bank-accounts', [OrgBankAccountController::class, 'index'])->name('bank-accounts.index');
        });
        Route::middleware('permission:bank_accounts.manage')->group(function () {
            Route::post('bank-accounts',                  [OrgBankAccountController::class, 'store'])->name('bank-accounts.store');
            Route::patch('bank-accounts/{bankAccount}',   [OrgBankAccountController::class, 'update'])->name('bank-accounts.update');
            Route::delete('bank-accounts/{bankAccount}',  [OrgBankAccountController::class, 'destroy'])->name('bank-accounts.destroy');
        });

        // F2 — Vendors
        Route::middleware('permission:vendors.view')->group(function () {
            Route::get('vendors', [\App\Http\Controllers\Finance\VendorController::class, 'index'])->name('vendors.index');
        });
        Route::middleware('permission:vendors.manage')->group(function () {
            Route::post('vendors',                  [\App\Http\Controllers\Finance\VendorController::class, 'store'])->name('vendors.store');
            Route::patch('vendors/{vendor}',        [\App\Http\Controllers\Finance\VendorController::class, 'update'])->name('vendors.update');
            Route::delete('vendors/{vendor}',       [\App\Http\Controllers\Finance\VendorController::class, 'destroy'])->name('vendors.destroy');
        });

        // F2 — AP Invoices
        Route::middleware('permission:ap_invoices.view')->group(function () {
            Route::get('ap-invoices',                       [\App\Http\Controllers\Finance\ApInvoiceController::class, 'index'])->name('ap-invoices.index');
            Route::get('ap-invoices/{apInvoice}',           [\App\Http\Controllers\Finance\ApInvoiceController::class, 'show'])->name('ap-invoices.show');
            Route::get('ap-invoices/{apInvoice}/print',     [\App\Http\Controllers\Finance\ApInvoiceController::class, 'print'])->name('ap-invoices.print');
        });
        Route::middleware('permission:ap_invoices.create')->group(function () {
            Route::post('ap-invoices',                      [\App\Http\Controllers\Finance\ApInvoiceController::class, 'store'])->name('ap-invoices.store');
            Route::post('ap-invoices/{apInvoice}/submit',   [\App\Http\Controllers\Finance\ApInvoiceController::class, 'submit'])->name('ap-invoices.submit');
            // Draft-only edit/delete (service guards the status).
            Route::patch('ap-invoices/{apInvoice}',         [\App\Http\Controllers\Finance\ApInvoiceController::class, 'update'])->name('ap-invoices.update');
            Route::delete('ap-invoices/{apInvoice}',        [\App\Http\Controllers\Finance\ApInvoiceController::class, 'destroy'])->name('ap-invoices.destroy');
        });
        Route::middleware('permission:ap_invoices.approve')->group(function () {
            Route::post('ap-invoices/{apInvoice}/approve',  [\App\Http\Controllers\Finance\ApInvoiceController::class, 'approve'])->name('ap-invoices.approve');
            Route::post('ap-invoices/{apInvoice}/cancel',   [\App\Http\Controllers\Finance\ApInvoiceController::class, 'cancel'])->name('ap-invoices.cancel');
        });

        // F2 — AP Payments
        Route::middleware('permission:ap_invoices.view')->group(function () {
            Route::get('ap-payments', [\App\Http\Controllers\Finance\ApPaymentController::class, 'index'])->name('ap-payments.index');
        });
        Route::middleware(['permission:ap_invoices.pay', '2fa:fresh'])->group(function () {
            Route::post('ap-payments',                            [\App\Http\Controllers\Finance\ApPaymentController::class, 'store'])->name('ap-payments.store');
            Route::post('ap-payments/{apPayment}/void',           [\App\Http\Controllers\Finance\ApPaymentController::class, 'void'])->name('ap-payments.void');
            Route::post('ap-payments/{apPayment}/disburse',       [\App\Http\Controllers\Finance\ApPaymentController::class, 'disburse'])->name('ap-payments.disburse');
        });

        // F2 — Journal Explorer
        Route::middleware('permission:journal.view')->group(function () {
            Route::get('journal',                  [\App\Http\Controllers\Finance\JournalController::class, 'index'])->name('journal.index');
            Route::get('journal/{journalEntry}',   [\App\Http\Controllers\Finance\JournalController::class, 'show'])->name('journal.show');
        });
        Route::middleware(['permission:journal.post_manual', '2fa:fresh'])->group(function () {
            Route::post('journal',                 [\App\Http\Controllers\Finance\JournalController::class, 'store'])->name('journal.store');
        });

        // F3 — Customers
        Route::middleware('permission:customers.view')->group(function () {
            Route::get('customers', [\App\Http\Controllers\Finance\CustomerController::class, 'index'])->name('customers.index');
        });
        Route::middleware('permission:customers.manage')->group(function () {
            Route::post('customers',               [\App\Http\Controllers\Finance\CustomerController::class, 'store'])->name('customers.store');
            Route::patch('customers/{customer}',   [\App\Http\Controllers\Finance\CustomerController::class, 'update'])->name('customers.update');
            Route::delete('customers/{customer}',  [\App\Http\Controllers\Finance\CustomerController::class, 'destroy'])->name('customers.destroy');
        });

        // F3 — AR Invoices
        Route::middleware('permission:ar_invoices.view')->group(function () {
            Route::get('ar-invoices',                       [\App\Http\Controllers\Finance\ArInvoiceController::class, 'index'])->name('ar-invoices.index');
            Route::get('ar-invoices/{arInvoice}',           [\App\Http\Controllers\Finance\ArInvoiceController::class, 'show'])->name('ar-invoices.show');
            Route::get('ar-invoices/{arInvoice}/print',     [\App\Http\Controllers\Finance\ArInvoiceController::class, 'print'])->name('ar-invoices.print');
        });
        Route::middleware('permission:ar_invoices.create')->group(function () {
            Route::post('ar-invoices',                      [\App\Http\Controllers\Finance\ArInvoiceController::class, 'store'])->name('ar-invoices.store');
            Route::post('ar-invoices/bulk',                 [\App\Http\Controllers\Finance\ArInvoiceController::class, 'bulkStore'])->name('ar-invoices.bulk-store');
            Route::post('ar-invoices/{arInvoice}/submit',   [\App\Http\Controllers\Finance\ArInvoiceController::class, 'submit'])->name('ar-invoices.submit');
            // Draft-only edit/delete (service guards the status).
            Route::patch('ar-invoices/{arInvoice}',         [\App\Http\Controllers\Finance\ArInvoiceController::class, 'update'])->name('ar-invoices.update');
            Route::delete('ar-invoices/{arInvoice}',        [\App\Http\Controllers\Finance\ArInvoiceController::class, 'destroy'])->name('ar-invoices.destroy');
        });
        Route::middleware('permission:ar_invoices.approve')->group(function () {
            Route::post('ar-invoices/{arInvoice}/approve',  [\App\Http\Controllers\Finance\ArInvoiceController::class, 'approve'])->name('ar-invoices.approve');
            Route::post('ar-invoices/{arInvoice}/cancel',   [\App\Http\Controllers\Finance\ArInvoiceController::class, 'cancel'])->name('ar-invoices.cancel');
        });
        Route::middleware(['permission:ar_invoices.write_off', '2fa:fresh'])->group(function () {
            // Bad-debt write-off destroys an AR receivable from the books and is
            // irreversible without manual JE intervention — gated behind a fresh
            // 2FA challenge, matching payroll.reverse / loans.disburse / DPA
            // privacy.fulfill posture.
            Route::post('ar-invoices/{arInvoice}/write-off', [\App\Http\Controllers\Finance\ArInvoiceController::class, 'writeOff'])->name('ar-invoices.write-off');
        });

        // F3 — AR Receipts
        Route::middleware('permission:ar_invoices.view')->group(function () {
            Route::get('ar-receipts', [\App\Http\Controllers\Finance\ArReceiptController::class, 'index'])->name('ar-receipts.index');
        });
        Route::middleware(['permission:ar_invoices.receive', '2fa:fresh'])->group(function () {
            // Recording or voiding a receipt moves money against an AR invoice's
            // outstanding balance. Same posture as payroll.disburse: fresh 2FA
            // challenge required so a stolen session can't drain receivables.
            Route::post('ar-receipts',                        [\App\Http\Controllers\Finance\ArReceiptController::class, 'store'])->name('ar-receipts.store');
            Route::post('ar-receipts/{arReceipt}/void',       [\App\Http\Controllers\Finance\ArReceiptController::class, 'void'])->name('ar-receipts.void');
        });

        // F3 — Statements
        Route::middleware('permission:statements.view')->group(function () {
            Route::get('statements',                  [\App\Http\Controllers\Finance\StatementController::class, 'index'])->name('statements.index');
            Route::get('statements/{customer}',       [\App\Http\Controllers\Finance\StatementController::class, 'show'])->name('statements.show');
        });

        // F4 — Payment Intents (Paystack gateway)
        Route::middleware('permission:gateway.view')->group(function () {
            Route::get('payment-intents',                       [\App\Http\Controllers\Finance\PaymentIntentController::class, 'index'])->name('payment-intents.index');
            Route::get('payment-intents/{paymentIntent}',       [\App\Http\Controllers\Finance\PaymentIntentController::class, 'show'])->name('payment-intents.show');
        });
        Route::middleware(['permission:gateway.create', '2fa:fresh'])->group(function () {
            Route::post('payment-intents',                      [\App\Http\Controllers\Finance\PaymentIntentController::class, 'store'])->name('payment-intents.store');
        });

        // F4-R — Refund operator flow
        Route::middleware(['permission:gateway.refund', '2fa:fresh'])->group(function () {
            Route::post('payment-intents/{paymentIntent}/refund', [\App\Http\Controllers\Finance\RefundController::class, 'store'])->name('payment-intents.refund');
            Route::post('payment-intents/bulk-refund',            [\App\Http\Controllers\Finance\RefundController::class, 'bulkStore'])->name('payment-intents.bulk-refund');
        });

        // F5 — Bank Reconciliation
        Route::prefix('reconciliation')->name('reconciliation.')->group(function () {
            Route::middleware('permission:reconciliation.view')->group(function () {
                Route::get('/',                          [\App\Http\Controllers\Finance\ReconciliationController::class, 'index'])->name('index');
                Route::get('/{bankStatement}',           [\App\Http\Controllers\Finance\ReconciliationController::class, 'show'])->name('show');
                Route::get('/{bankStatement}/print',     [\App\Http\Controllers\Finance\ReconciliationController::class, 'print'])->name('print');
            });
            Route::middleware('permission:reconciliation.import')->group(function () {
                Route::post('/',                         [\App\Http\Controllers\Finance\ReconciliationController::class, 'store'])->name('store');
            });
            Route::middleware('permission:reconciliation.match')->group(function () {
                Route::post('/lines/{line}/link',        [\App\Http\Controllers\Finance\ReconciliationController::class, 'link'])->name('link');
                Route::post('/lines/{line}/unlink',      [\App\Http\Controllers\Finance\ReconciliationController::class, 'unlink'])->name('unlink');
                Route::post('/{bankStatement}/rematch',  [\App\Http\Controllers\Finance\ReconciliationController::class, 'rematch'])->name('rematch');
            });
            Route::middleware(['permission:reconciliation.adjust', '2fa:fresh'])->group(function () {
                Route::post('/lines/{line}/adjust',      [\App\Http\Controllers\Finance\ReconciliationController::class, 'adjust'])->name('adjust');
            });
        });
    });
});

// ── Phase 4: SSO (WS19) ──
// SSO initiate / callback are public — they replace the password login flow.
Route::prefix('auth/sso')->name('sso.')->middleware('throttle:30,1')->group(function () {
    Route::get('{slug}',           [\App\Http\Controllers\Auth\SsoController::class, 'initiate'])->name('initiate');
    Route::match(['get', 'post'], '{slug}/callback', [\App\Http\Controllers\Auth\SsoController::class, 'callback'])->name('callback');
});

// ── M1: Billing & Fees (Members + Fee Catalog + Billing runs) ──
Route::middleware(['auth'])->group(function () {
    Route::prefix('admin')->name('billing.')->group(function () {
        // Member directory
        Route::middleware('permission:members.view')->group(function () {
            Route::get('/members',            [\App\Http\Controllers\Billing\MemberController::class, 'index'])->name('members.index');
            Route::get('/members/{member}',   [\App\Http\Controllers\Billing\MemberController::class, 'show'])->name('members.show');
        });
        Route::middleware('permission:members.manage')->group(function () {
            Route::post('/members',           [\App\Http\Controllers\Billing\MemberController::class, 'store'])->name('members.store');
            Route::patch('/members/{member}', [\App\Http\Controllers\Billing\MemberController::class, 'update'])->name('members.update');
            Route::delete('/members/{member}',[\App\Http\Controllers\Billing\MemberController::class, 'destroy'])->name('members.destroy');
        });

        // Fee catalog
        Route::middleware('permission:fee_catalog.view')->group(function () {
            Route::get('/fee-catalog',                  [\App\Http\Controllers\Billing\FeeProductController::class, 'index'])->name('fee-catalog.index');
        });
        Route::middleware('permission:fee_catalog.manage')->group(function () {
            Route::post('/fee-catalog',                 [\App\Http\Controllers\Billing\FeeProductController::class, 'store'])->name('fee-catalog.store');
            Route::patch('/fee-catalog/{feeProduct}',   [\App\Http\Controllers\Billing\FeeProductController::class, 'update'])->name('fee-catalog.update');
            Route::delete('/fee-catalog/{feeProduct}',  [\App\Http\Controllers\Billing\FeeProductController::class, 'destroy'])->name('fee-catalog.destroy');
        });

        // Billing runs (mint AR invoices from assignments)
        Route::get('/billing-runs',   [\App\Http\Controllers\Billing\BillingRunController::class, 'index'])
            ->middleware('permission:billing.run')->name('runs.index');
        Route::post('/billing-runs',  [\App\Http\Controllers\Billing\BillingRunController::class, 'store'])
            ->middleware(['permission:billing.run', '2fa:fresh'])->name('runs.store');
    });
});

// SSO provider admin (authenticated; audit captured globally)
Route::middleware(['auth'])->group(function () {
    Route::prefix('admin/sso/providers')->name('sso-admin.')->group(function () {
        Route::get('/',          [\App\Http\Controllers\Admin\SsoProviderController::class, 'index'])
            ->middleware('permission:sso.manage')->name('index');
        Route::post('/',         [\App\Http\Controllers\Admin\SsoProviderController::class, 'store'])
            ->middleware(['permission:sso.manage', '2fa:fresh'])->name('store');
        Route::patch('{provider}', [\App\Http\Controllers\Admin\SsoProviderController::class, 'update'])
            ->middleware(['permission:sso.manage', '2fa:fresh'])->name('update');
        Route::delete('{provider}',[\App\Http\Controllers\Admin\SsoProviderController::class, 'destroy'])
            ->middleware(['permission:sso.manage', '2fa:fresh'])->name('destroy');
    });
});

// ── Auditors: hub + incoming-invoice vetting ────────────────────────────────
Route::middleware(['auth', 'verified'])->prefix('auditor')->name('auditor.')->group(function () {
    Route::get('/', [\App\Http\Controllers\AuditorController::class, 'hub'])
        ->middleware('permission:auditor.hub')->name('hub');

    Route::middleware('permission:incoming_invoices.view')->group(function () {
        Route::get('incoming-invoices',                 [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'index'])->name('incoming-invoices.index');
        Route::get('incoming-invoices/create',          [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'create'])->name('incoming-invoices.create');
        Route::get('incoming-invoices/{incomingInvoice}', [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'show'])->name('incoming-invoices.show');
        Route::get('incoming-invoices/{incomingInvoice}/attachments/{attachment}', [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'download'])->name('incoming-invoices.download');
    });
    Route::middleware('permission:incoming_invoices.submit')->group(function () {
        Route::post('incoming-invoices',                        [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'store'])->name('incoming-invoices.store');
        Route::patch('incoming-invoices/{incomingInvoice}',     [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'update'])->name('incoming-invoices.update');
        Route::post('incoming-invoices/{incomingInvoice}/submit',[\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'submit'])->name('incoming-invoices.submit');
    });
    Route::middleware('permission:incoming_invoices.vet')->group(function () {
        Route::post('incoming-invoices/{incomingInvoice}/vet',        [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'vet'])->name('incoming-invoices.vet');
        Route::post('incoming-invoices/{incomingInvoice}/vet-return', [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'vetReturn'])->name('incoming-invoices.vet-return');
    });
    Route::middleware('permission:incoming_invoices.approve')->group(function () {
        Route::post('incoming-invoices/{incomingInvoice}/approve',    [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'approve'])->name('incoming-invoices.approve');
        Route::post('incoming-invoices/{incomingInvoice}/ceo-return', [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'ceoReturn'])->name('incoming-invoices.ceo-return');
    });
    Route::middleware('permission:incoming_invoices.post')->group(function () {
        Route::post('incoming-invoices/{incomingInvoice}/post', [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'post'])->name('incoming-invoices.post');
    });

    // Asset audits
    Route::middleware('permission:asset_audits.view')->group(function () {
        Route::get('asset-audits',                 [\App\Http\Controllers\Auditor\AssetAuditController::class, 'index'])->name('asset-audits.index');
        Route::get('asset-audits/create',          [\App\Http\Controllers\Auditor\AssetAuditController::class, 'create'])->name('asset-audits.create');
        Route::get('asset-audits/{assetAudit}',    [\App\Http\Controllers\Auditor\AssetAuditController::class, 'show'])->name('asset-audits.show');
    });
    Route::middleware('permission:asset_audits.manage')->group(function () {
        Route::post('asset-audits',                              [\App\Http\Controllers\Auditor\AssetAuditController::class, 'store'])->name('asset-audits.store');
        Route::post('asset-audits/{assetAudit}/lines/{line}/count',   [\App\Http\Controllers\Auditor\AssetAuditController::class, 'count'])->name('asset-audits.count');
        Route::post('asset-audits/{assetAudit}/lines/{line}/resolve', [\App\Http\Controllers\Auditor\AssetAuditController::class, 'resolve'])->name('asset-audits.resolve');
        Route::post('asset-audits/{assetAudit}/complete',       [\App\Http\Controllers\Auditor\AssetAuditController::class, 'complete'])->name('asset-audits.complete');
        Route::post('asset-audits/{assetAudit}/cancel',         [\App\Http\Controllers\Auditor\AssetAuditController::class, 'cancel'])->name('asset-audits.cancel');
    });
});

require __DIR__.'/auth.php';
