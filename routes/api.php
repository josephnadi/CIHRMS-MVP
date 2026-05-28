<?php

use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Api\v1\AttendanceController as AttendanceApiController;
use App\Http\Controllers\Api\v1\EmployeesApiController;
use App\Http\Controllers\Api\v1\HealthController as HealthApiController;
use App\Http\Controllers\Api\v1\MeController as MeApiController;
use App\Http\Controllers\Api\v1\MembersApiController;
use App\Http\Controllers\Api\v1\OpenApiController as OpenApiSpecController;
use App\Http\Controllers\Api\v1\PayrollApiController;
use App\Http\Controllers\Api\v1\PayrollController as PayrollV1Controller;
use App\Http\Controllers\Api\v1\WebhookSubscriptionController;
use App\Http\Controllers\OpenApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public-facing OpenAPI spec (no auth) — partners and SDK generators
| can discover the API surface before exchanging credentials.
|--------------------------------------------------------------------------
*/
Route::get('/v1/openapi.yaml', [OpenApiSpecController::class, 'yaml'])->name('api.v1.openapi.yaml');
Route::get('/v1/openapi.json', [OpenApiSpecController::class, 'json'])->name('api.v1.openapi.json');

// Health probe — no auth, no rate limit beyond throttle
Route::get('/v1/health', [HealthApiController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('api.v1.health');

// Legacy /v1/openapi.yaml backed by the older Web-facing OpenApiController too,
// retained so partners using the previous URL don't break.
Route::get('/v1/openapi-legacy', [OpenApiController::class, 'show'])
    ->name('api.v1.openapi.legacy');

/*
|--------------------------------------------------------------------------
| CIHRMS Public API · v1 (authenticated, scoped)
|--------------------------------------------------------------------------
| All endpoints require Sanctum auth + the appropriate ability (scope).
| AuditTrail middleware ensures every external call shows in audit_logs.
| Per-route `api.scope:<ability>` enforces token scopes via the sidecar
| metadata table (revoked / expired / IP-allowlist checks).
*/

Route::middleware(['auth:sanctum', 'throttle:api'])
    ->prefix('v1')->name('api.v1.')->group(function () {

        // Token introspection — every authenticated token can hit this
        Route::get('/me', [MeApiController::class, 'show'])->name('me');

        // Employees
        Route::middleware('api.scope:employees:read')->group(function () {
            Route::get('/employees',           [EmployeesApiController::class, 'index'])->name('employees.index');
            Route::get('/employees/{employee}',[EmployeesApiController::class, 'show'])->name('employees.show');
        });

        // Payroll
        Route::middleware('api.scope:payroll:read')->group(function () {
            Route::get('/payroll/runs',                [PayrollApiController::class, 'index'])->name('payroll.index');
            Route::get('/payroll/runs/{run}',          [PayrollApiController::class, 'show'])->name('payroll.show');
            Route::get('/payroll/runs/{run}/returns',  [PayrollV1Controller::class, 'returns'])->name('payroll.returns');
        });

        // Statutory returns (separately scoped — these contain GRA/SSNIT/Tier-2 files)
        Route::middleware('api.scope:statutory:export')->group(function () {
            Route::get('/payroll/runs/{run}/returns/{return}/download',
                [PayrollV1Controller::class, 'downloadReturn'])
                ->name('payroll.returns.download');
        });

        // Attendance
        Route::middleware('api.scope:attendance:read')->group(function () {
            Route::get('/attendance/summaries', [AttendanceApiController::class, 'index'])->name('attendance.summaries');
        });

        // ── M3: Billing & Fees partner-sync API ──
        // Read-only listing — `members:read` scope.
        Route::middleware('api.scope:members:read')->group(function () {
            Route::get('/members',          [MembersApiController::class, 'index'])->name('members.index');
            Route::get('/members/{member}', [MembersApiController::class, 'show'])->name('members.show');
        });
        // Invoices for a member — needs both scopes so a token can read
        // members but not their financials unless explicitly granted.
        Route::middleware(['api.scope:members:read', 'api.scope:invoices:read'])->group(function () {
            Route::get('/members/{member}/invoices', [MembersApiController::class, 'invoices'])
                ->name('members.invoices');
        });
        // Mint a Paystack link for a partner-driven payment — reuses the
        // existing `gateway:create` scope so the same audit trail and
        // 2FA-fresh policy applies as in the admin UI.
        Route::middleware(['api.scope:members:read', 'api.scope:gateway:create'])->group(function () {
            Route::post('/members/{member}/payment-intents', [MembersApiController::class, 'paymentIntent'])
                ->name('members.payment-intents.store');
        });

        // Webhook subscriptions (only tokens with webhooks:manage)
        Route::middleware('api.scope:webhooks:manage')->group(function () {
            Route::get('/webhook-subscriptions',                  [WebhookSubscriptionController::class, 'index'])->name('webhooks.index');
            Route::post('/webhook-subscriptions',                 [WebhookSubscriptionController::class, 'store'])->name('webhooks.store');
            Route::delete('/webhook-subscriptions/{subscription}',[WebhookSubscriptionController::class, 'destroy'])->name('webhooks.destroy');
        });

        // First-party analytics ingestion (legacy SPA route)
        Route::post('/analytics-events',     [AnalyticsController::class, 'store'])->name('analytics-events.store');
        Route::post('/ai/employee-summary',  [AiAssistantController::class, 'summary'])->name('ai.employee-summary');
    });

// Legacy un-prefixed for back-compat with first-party SPA
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/analytics-events',     [AnalyticsController::class, 'store']);
    Route::post('/ai/employee-summary',  [AiAssistantController::class, 'summary']);
});
