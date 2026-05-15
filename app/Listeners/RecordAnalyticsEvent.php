<?php

namespace App\Listeners;

use App\Events\EmployeeCreated;
use App\Events\LeaveRequested;
use App\Events\LeaveStatusUpdated;
use App\Events\TicketCreated;
use App\Models\AnalyticsEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

class RecordAnalyticsEvent implements ShouldQueue
{
    public string $queue = 'analytics';

    public function handle(object $event): void
    {
        [$eventName, $meta] = match(true) {
            $event instanceof EmployeeCreated => [
                'employee.created',
                ['employee_id' => $event->employee->id, 'employee_no' => $event->employee->employee_no],
            ],
            $event instanceof LeaveRequested => [
                'leave.requested',
                ['leave_id' => $event->leaveRequest->id, 'type' => $event->leaveRequest->type?->value],
            ],
            $event instanceof LeaveStatusUpdated => [
                'leave.status_updated',
                ['leave_id' => $event->leaveRequest->id, 'status' => $event->leaveRequest->status?->value],
            ],
            $event instanceof TicketCreated => [
                'ticket.created',
                ['ticket_id' => $event->ticket->id, 'priority' => $event->ticket->priority?->value],
            ],
            default => [class_basename($event), []],
        };

        AnalyticsEvent::create([
            'user_id' => $event->actor?->id,
            'event'   => $eventName,
            'meta'    => $meta,
        ]);
    }
}
