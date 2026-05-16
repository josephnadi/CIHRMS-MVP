<?php

use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Api\v1\EmployeesApiController;
use App\Http\Controllers\Api\v1\PayrollApiController;
use App\Http\Controllers\Api\v1\WebhookSubscriptionController;
use App\Http\Controllers\OpenApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public OpenAPI 3.0 spec — useful for SDK generation and discovery tools.
Route::get('/v1/openapi.yaml', [OpenApiController::class, 'show'])->name('api.v1.openapi');

/*
|--------------------------------------------------------------------------
| CIHRMS Public API · v1
|--------------------------------------------------------------------------
| Sanctum-authenticated. AuditTrail middleware ensures every external API
| call shows in audit_logs alongside web traffic. OpenAPI 3.0 spec lives
| at storage/api/openapi.yaml.
*/

Route::middleware(['auth:sanctum', 'audit'])->prefix('v1')->name('api.v1.')->group(function () {

    Route::get('/me', function (Request $request) {
        $user = $request->user();
        return response()->json(['data' => [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'staff_id'    => $user->staff_id,
            'role'        => $user->role?->value ?? $user->role,
            'permissions' => $user->allPermissions(),
        ]]);
    })->name('me');

    Route::get('/employees',                              [EmployeesApiController::class, 'index'])->name('employees.index');
    Route::get('/employees/{employee}',                   [EmployeesApiController::class, 'show'])->name('employees.show');

    Route::get('/payroll/runs',                           [PayrollApiController::class, 'index'])->name('payroll.index');
    Route::get('/payroll/runs/{run}',                     [PayrollApiController::class, 'show'])->name('payroll.show');

    Route::get('/webhook-subscriptions',                  [WebhookSubscriptionController::class, 'index'])->name('webhooks.index');
    Route::post('/webhook-subscriptions',                 [WebhookSubscriptionController::class, 'store'])->name('webhooks.store');
    Route::delete('/webhook-subscriptions/{subscription}', [WebhookSubscriptionController::class, 'destroy'])->name('webhooks.destroy');

    Route::post('/analytics-events',                      [AnalyticsController::class, 'store'])->name('analytics-events.store');
    Route::post('/ai/employee-summary',                   [AiAssistantController::class, 'summary'])->name('ai.employee-summary');
});

// Legacy un-prefixed
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/analytics-events',     [AnalyticsController::class, 'store']);
    Route::post('/ai/employee-summary',  [AiAssistantController::class, 'summary']);
});
