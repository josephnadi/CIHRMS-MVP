<?php

namespace App\Listeners\Incident;

use App\Events\Incident\IncidentMessagePosted;
use App\Models\Notification;

class NotifyMessageRecipients
{
    public function handle(IncidentMessagePosted $e): void
    {
        $report = $e->message->report()->with(['employee.user', 'currentAssignees'])->first();
        if (! $report) return;

        $authorId = $e->message->author_id;
        $recipients = collect()
            ->push($report->employee?->user_id)
            ->merge($report->currentAssignees->pluck('id'))
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $authorId);

        foreach ($recipients as $userId) {
            Notification::create([
                'notifiable_type' => \App\Models\User::class,
                'notifiable_id' => $userId,
                'type' => 'incident.message',
                'data' => [
                    'incident_report_id' => $report->id,
                    'message' => $report->title,
                    'kind' => 'incident.message',
                    'route' => 'incidents.show',
                ],
            ]);
        }
    }
}
