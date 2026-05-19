<?php

namespace App\Policies;

use App\Enums\IncidentStatus;
use App\Models\IncidentReport;
use App\Models\IncidentReportAttachment;
use App\Models\User;

class IncidentReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, IncidentReport $report): bool
    {
        return $report->isInCircle($user);
    }

    public function create(User $user): bool
    {
        return $user->employee !== null;
    }

    public function update(User $user, IncidentReport $report): bool
    {
        return $report->employee?->user_id === $user->id
            && $report->status === IncidentStatus::Open
            && $report->currentAssignees()->count() === 0;
    }

    public function close(User $user, IncidentReport $report): bool
    {
        return $report->currentAssignees()->where('users.id', $user->id)->exists();
    }

    public function assign(User $user, IncidentReport $report): bool
    {
        return $report->employee?->user_id === $user->id
            || $report->currentAssignees()->where('users.id', $user->id)->exists();
    }

    public function postMessage(User $user, IncidentReport $report): bool
    {
        return $this->view($user, $report) && $report->status !== IncidentStatus::Closed;
    }

    public function downloadAttachment(User $user, IncidentReportAttachment $attachment): bool
    {
        return $this->view($user, $attachment->reportRoot());
    }
}
