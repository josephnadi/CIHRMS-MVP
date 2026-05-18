<?php

namespace App\Listeners\Incident;

use App\Events\Incident\IncidentReportUnassigned;
use App\Models\Notification;

class NotifyUnassigned
{
    public function handle(IncidentReportUnassigned $e): void
    {
        // Spec §6 note: this row is written BEFORE removed_at takes effect
        // server-side, so the recipient reads the title once; the deep-link
        // will return 403 on next click.
        Notification::create([
            'notifiable_type' => \App\Models\User::class,
            'notifiable_id' => $e->removedAssignee->id,
            'type' => 'incident.unassigned',
            'data' => [
                'incident_report_id' => $e->report->id,
                'message' => $e->report->title,
                'kind' => 'incident.unassigned',
                'route' => 'incidents.show',
            ],
        ]);
    }
}
