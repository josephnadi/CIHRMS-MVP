<?php

namespace App\Listeners\Incident;

use App\Events\Incident\IncidentReportAssigned;
use App\Models\Notification;

class NotifyAssignee
{
    public function handle(IncidentReportAssigned $e): void
    {
        Notification::create([
            'notifiable_type' => \App\Models\User::class,
            'notifiable_id' => $e->assignee->id,
            'type' => 'incident.assigned',
            'data' => [
                'incident_report_id' => $e->report->id,
                'title' => $e->report->title,
                'route' => 'incidents.show',
            ],
        ]);
    }
}
