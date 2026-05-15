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
