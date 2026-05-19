<?php

namespace App\Listeners\Incident;

use App\Events\Incident\IncidentReportReopened;
use App\Models\Notification;

class NotifyCircleOnReopen
{
    public function handle(IncidentReportReopened $e): void
    {
        $report = $e->report->load(['employee.user', 'currentAssignees']);
        $recipients = collect()
            ->push($report->employee?->user_id)
            ->merge($report->currentAssignees->pluck('id'))
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $e->actor->id);

        foreach ($recipients as $userId) {
            Notification::create([
                'notifiable_type' => \App\Models\User::class,
                'notifiable_id' => $userId,
                'type' => 'incident.reopened',
                'data' => [
                    'incident_report_id' => $report->id,
                    'message' => $report->title,
                    'kind' => 'incident.reopened',
                    'route' => 'incidents.show',
                ],
            ]);
        }
    }
}
