<?php

use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\NotificationChannelController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecruitmentController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\PayrollRunController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\IdentityVerificationController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\BenefitsController;
use App\Http\Controllers\LoanAccountController;
use App\Http\Controllers\OffboardingController;
use App\Http\Controllers\WhistleblowerPublicController;
use App\Http\Controllers\WhistleblowerAdminController;
use App\Http\Controllers\AuditorGeneralReportController;
use App\Http\Controllers\Webhooks\BiometricWebhookController;
use App\Http\Controllers\AiAssistantController;
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

// Public careers portal (unauthenticated)
Route::get('/careers/{job}',        [RecruitmentController::class, 'showPublic'])->name('careers.show');
Route::post('/careers/{job}/apply', [RecruitmentController::class, 'apply'])->name('careers.apply');

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
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

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
    Route::get('governance',  [\App\Http\Controllers\StaticPageController::class, 'governance'])         ->name('governance');
    Route::get('assets',      fn () => redirect()->route('assets.index'))->name('assets');
    Route::get('benefits',    fn () => redirect()->route('benefits.index'))->name('benefits');

    // Performance: dedicated analytics page.
    Route::get('performance', [PerformanceController::class, 'index'])                                   ->name('performance');
});

// Department portals (one route, one Vue page, slug-driven)
Route::middleware(['auth', 'verified'])->prefix('departments')->name('departments.')->group(function () {
    Route::get('portal/{slug}', [\App\Http\Controllers\StaticPageController::class, 'department'])
        ->whereIn('slug', ['it', 'hr', 'marketing', 'finance'])
        ->name('portal');
});

Route::middleware(['auth', 'audit'])->group(function () {
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
    });

    // Departments
    Route::get('/departments',  [EmployeeController::class, 'departments'])
        ->middleware('permission:employees.manage')
        ->name('departments.index');
    Route::post('/departments', [EmployeeController::class, 'storeDepartment'])
        ->middleware('permission:employees.manage')
        ->name('departments.store');

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
        Route::post('{employee}/avatar',        [EmployeeController::class, 'uploadAvatar'])    ->name('avatar.store');
        Route::post('{employee}/skills',        [EmployeeController::class, 'storeSkill'])      ->name('skills.store');
        Route::delete('{employee}/skills/{skill}', [EmployeeController::class, 'destroySkill']) ->name('skills.destroy');
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
        Route::patch('{ticket}',   [TicketController::class, 'updateStatus'])
            ->middleware('permission:tickets.manage')
            ->name('update');
        Route::delete('{ticket}',  [TicketController::class, 'destroy'])
            ->middleware('permission:tickets.manage')
            ->name('destroy');
    });

    // Complaints
    Route::prefix('complaints')->name('complaints.')->group(function () {
        Route::get('/',                    [ComplaintController::class, 'index'])
            ->middleware('permission:complaints.manage')
            ->name('index');
        Route::post('/',                   [ComplaintController::class, 'store'])
            ->middleware('permission:complaints.create')
            ->name('store');
        Route::get('/track',               [ComplaintController::class, 'track'])
            ->name('track');
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
        Route::post('{jobPosting}/apply',           [RecruitmentController::class, 'apply'])
            ->middleware('permission:recruitment.apply')
            ->name('apply');
    });
    Route::patch('applicants/{applicant}', [RecruitmentController::class, 'updateApplicant'])
        ->middleware('permission:recruitment.manage')
        ->name('applicants.update');
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

    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/',          [NotificationController::class, 'index'])         ->name('index');
        Route::post('/read-all', [NotificationController::class, 'readAll'])       ->name('readAll');
        Route::get('/channels',  [NotificationChannelController::class, 'edit'])   ->name('channels.edit');
        Route::patch('/channels',[NotificationChannelController::class, 'update']) ->name('channels.update');
    });

    // AI assistant
    Route::post('/ai/employee-summary', [AiAssistantController::class, 'summary'])->name('ai.employee-summary');

    // ── Phase 1: Statutory Payroll Runs ──
    Route::prefix('payroll-runs')->name('payroll-runs.')->group(function () {
        Route::get('/',                       [PayrollRunController::class, 'index'])
            ->middleware('permission:payroll.view_all')->name('index');
        Route::post('/',                      [PayrollRunController::class, 'store'])
            ->middleware('permission:payroll.run')->name('store');
        Route::get('{run}',                   [PayrollRunController::class, 'show'])
            ->middleware('permission:payroll.view_all')->name('show');
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
            ->middleware('permission:attendance.clock_self')->name('clock');
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
        Route::post('{case}/complete',                     [OffboardingController::class, 'complete'])
            ->middleware(['permission:offboarding.manage', '2fa:fresh'])->name('complete');
        Route::post('{case}/cancel',                       [OffboardingController::class, 'cancel'])
            ->middleware('permission:offboarding.manage')->name('cancel');
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
            Route::patch('/{claim}/decide',   [BenefitsController::class, 'decideClaim'])
                ->middleware('permission:benefits.manage')->name('decide');
        });

        Route::get('/enrolments/{enrolment}/e-card', [BenefitsController::class, 'downloadECard'])->name('e-card');
    });

    // ── Phase 1: Two-factor auth ──
    Route::prefix('two-factor')->name('two-factor.')->group(function () {
        Route::get('/enroll',     [TwoFactorController::class, 'enroll'])        ->name('enroll');
        Route::post('/enroll',    [TwoFactorController::class, 'confirm'])       ->name('confirm');
        Route::get('/challenge',  [TwoFactorController::class, 'challengeForm']) ->name('challenge');
        Route::post('/challenge', [TwoFactorController::class, 'challenge'])     ->name('challenge.submit');
        Route::delete('/',        [TwoFactorController::class, 'disable'])       ->name('disable');
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
});

require __DIR__.'/auth.php';
