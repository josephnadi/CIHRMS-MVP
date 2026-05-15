<?php

namespace App\Listeners;

use App\Events\LeaveRequested;
use App\Integrations\MessagingDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * When a leave request is filed, fan it out to the requester's manager (or HR
 * if there is no manager) across whichever channels they prefer (Slack DM,
 * Teams card to the HR channel, WhatsApp template). Also broadcasts to the
 * configured Slack/Teams HR channel when the slack_leave_approvals flag is on.
 */
class NotifyManagerOfLeaveRequest implements ShouldQueue
{
    public string $queue = 'integrations';

    public function __construct(protected MessagingDispatcher $dispatcher) {}

    public function handle(LeaveRequested $event): void
    {
        $leave    = $event->leaveRequest->loadMissing(['employee.user', 'employee.manager.user']);
        $employee = $leave->employee;
        if (! $employee) return;

        $manager = $employee->manager?->user;
        $type    = $leave->type?->label() ?? (string) $leave->type;
        $start   = $leave->start_date?->format('d M Y');
        $end     = $leave->end_date?->format('d M Y');

        $params = [
            'employee' => $employee->user?->name ?? "Employee #{$employee->id}",
            'type'     => $type,
            'start'    => $start,
            'end'      => $end,
            'leave_id' => $leave->id,
            'url'      => url("/dashboard?module=leave&leave_id={$leave->id}"),
        ];

        $body = "Leave request from {$params['employee']} — {$type}, {$start} → {$end}.";

        // Direct manager DM
        if ($manager) {
            try {
                $this->dispatcher->send($manager, $body, [
                    'slack_template'    => 'leave_approval',
                    'teams_template'    => 'leave_approval',
                    'whatsapp_template' => config('integrations.feature_flags.whatsapp_leave_template'),
                    'whatsapp_params'   => [$params['employee'], $type, "$start → $end"],
                    'params'            => $params,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[messaging] manager notify failed', ['error' => $e->getMessage()]);
            }
        }

        // Optional broadcast to ops channels
        if (config('integrations.feature_flags.slack_leave_approvals')) {
            $this->dispatcher->broadcast($body, [
                'slack_template' => 'leave_approval',
                'teams_template' => 'leave_approval',
                'params'         => $params,
            ]);
        }
    }
}
