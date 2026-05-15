<?php

namespace App\Listeners;

use App\Events\AttendanceCorrectionDecided;
use App\Events\AttendanceCorrectionRequested;
use App\Events\EmployeeCreated;
use App\Events\LeaveRequested;
use App\Events\LeaveStatusUpdated;
use App\Events\PayslipGenerated;
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
            $event instanceof PayslipGenerated => [
                'payslip.generated',
                ['payslip_id' => $event->payment->id ?? null, 'employee_id' => $event->payment->employee_id ?? null],
            ],
            $event instanceof AttendanceCorrectionRequested => [
                'attendance.correction.requested',
                ['correction_id' => $event->correction->id, 'employee_id' => $event->correction->employee_id],
            ],
            $event instanceof AttendanceCorrectionDecided => [
                'attendance.correction.decided',
                ['correction_id' => $event->correction->id, 'status' => $event->correction->status?->value],
            ],
            $event instanceof \App\Events\AssetAssigned => [
                'asset.assigned',
                ['assignment_id' => $event->assignment->id, 'asset_id' => $event->assignment->asset_id, 'employee_id' => $event->assignment->employee_id],
            ],
            $event instanceof \App\Events\AssetReturned => [
                'asset.returned',
                ['assignment_id' => $event->assignment->id, 'asset_id' => $event->assignment->asset_id, 'condition' => $event->assignment->condition_on_return?->value],
            ],
            $event instanceof \App\Events\AssetMaintenanceLogged => [
                'asset.maintenance.logged',
                ['maintenance_id' => $event->maintenance->id, 'asset_id' => $event->maintenance->asset_id, 'type' => $event->maintenance->type?->value],
            ],
            $event instanceof \App\Events\AssetMaintenanceCompleted => [
                'asset.maintenance.completed',
                ['maintenance_id' => $event->maintenance->id, 'asset_id' => $event->maintenance->asset_id],
            ],
            $event instanceof \App\Events\AssetRetired => [
                'asset.retired',
                ['asset_id' => $event->asset->id, 'asset_tag' => $event->asset->asset_tag],
            ],
            $event instanceof \App\Events\AssetMarkedLost => [
                'asset.lost',
                ['asset_id' => $event->asset->id, 'asset_tag' => $event->asset->asset_tag],
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
