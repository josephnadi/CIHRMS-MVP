<?php

namespace App\Listeners\Incident;

use App\Events\Incident\IncidentReportClosed;
use App\Models\Notification;

class NotifySubmitterOnClose
{
    public function handle(IncidentReportClosed $e): void
    {
        $submitterUserId = $e->report->employee?->user_id;
        if (! $submitterUserId) return;

        Notification::create([
            'notifiable_type' => \App\Models\User::class,
            'notifiable_id' => $submitterUserId,
            'type' => 'incident.closed',
            'data' => [
                'incident_report_id' => $e->report->id,
                'message' => $e->report->title,
                'kind' => 'incident.closed',
                'route' => 'incidents.show',
            ],
        ]);
    }
}
