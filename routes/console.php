<?php

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Notifications\LeaveApprovalReminder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-reject stale pending leave requests whose end date has passed
Schedule::call(function () {
    LeaveRequest::where('status', LeaveStatus::Pending->value)
        ->where('end_date', '<', today())
        ->update(['status' => LeaveStatus::Rejected->value]);
})->dailyAt('00:05');

// Remind managers of leave requests pending more than 3 days
Schedule::call(function () {
    LeaveRequest::pending()
        ->where('created_at', '<', now()->subDays(3))
        ->with('employee.user')
        ->get()
        ->each(fn ($lr) => $lr->employee?->user?->notify(new LeaveApprovalReminder($lr)));
})->dailyAt('08:00');

// Refresh integration OAuth tokens about to expire (every 30 minutes)
Schedule::command('integrations:refresh-tokens --minutes=10')->everyThirtyMinutes()->withoutOverlapping();

// Mark absent: daily, materializes attendance summaries for employees with no events
Schedule::command('attendance:mark-absent')->dailyAt('23:55')->withoutOverlapping();

// Assets: regenerate depreciation snapshots monthly on the 1st at 02:00
Schedule::command('assets:regenerate-depreciation')->monthlyOn(1, '02:00')->withoutOverlapping();

// Governance: daily certification-expiry reminders at 08:00
Schedule::command('governance:certification-reminders')->dailyAt('08:00')->withoutOverlapping();

// Tamper-evident audit chain — verified daily; super_admins notified on any
// mismatch. Runs at 03:00 so it lands after the day's writes but well before
// office hours; a broken chain is a security incident, and we want a human
// alert waiting in the inbox by the time HR walks in.
Schedule::command('audit:verify-chain --notify')->dailyAt('03:00')->withoutOverlapping();

// Ghana Card re-verification reminder — flags employees whose 12-month NIA
// validity expires within 30 days. Sends one notification per expiring row;
// HR sees the upcoming queue, the employee gets the inbox nudge.
Schedule::command('identity:expiring --window=30')->dailyAt('07:30')->withoutOverlapping();

// F4-R follow-up: expire stale Paystack payment intents nightly. Without this
// schedule, `pending` intents whose `expires_at` has passed accumulate
// indefinitely. expireStale() flips them to `expired` so the UI surfaces a
// clean state and the count metric on the finance hub stays accurate.
Schedule::call(function () {
    app(\App\Services\Finance\PaymentIntentService::class)->expireStale();
})->dailyAt('02:15')->name('payment-intents:expire-stale')->onOneServer();

// Belt-and-braces SMS retry sweep — every 5 minutes, picks up any
// SmsMessage row stuck in Queued for > 10 min (worker crash, queue pause)
// and re-dispatches SendSmsJob. The job is idempotent.
Schedule::command('messaging:sweep-stuck-sms')
    ->everyFiveMinutes()
    ->withoutOverlapping();
