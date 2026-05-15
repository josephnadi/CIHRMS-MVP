<?php

use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AnalyticsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'audit'])->prefix('v1')->group(function () {
    Route::post('/analytics-events', [AnalyticsController::class, 'store'])->name('api.v1.analytics-events.store');
    Route::post('/ai/employee-summary', [AiAssistantController::class, 'summary'])->name('api.v1.ai.employee-summary');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/analytics-events', [AnalyticsController::class, 'store']);
    Route::post('/ai/employee-summary', [AiAssistantController::class, 'summary']);
});
