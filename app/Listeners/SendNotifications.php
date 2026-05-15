<?php

namespace App\Listeners;

use App\Events\EmployeeCreated;
use App\Events\LeaveStatusUpdated;
use App\Notifications\LeaveStatusChanged;
use App\Notifications\NewEmployeeWelcome;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(object $event): void
    {
        if ($event instanceof LeaveStatusUpdated) {
            $event->leaveRequest->employee?->user?->notify(new LeaveStatusChanged($event->leaveRequest));
        } elseif ($event instanceof EmployeeCreated) {
            $event->employee->user?->notify(new NewEmployeeWelcome($event->employee));
        }
    }
}
