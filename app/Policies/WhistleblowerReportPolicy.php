<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhistleblowerReport;

class WhistleblowerReportPolicy
{
    /**
     * Note: super_admin does NOT get an automatic `before()` true — even
     * super-admin must hold `whistleblower.manage` to see case content,
     * preserving the segregated-investigator principle. (Super-admin gets
     * the wildcard `*` permission via legacy ROLE_PERMISSIONS anyway, so
     * this is more about *intent* than blocking sa.)
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('whistleblower.investigate')
            || $user->hasPermission('whistleblower.manage')
            || $user->hasPermission('whistleblower.view_all');
    }

    public function view(User $user, WhistleblowerReport $report): bool
    {
        if ($user->hasPermission('whistleblower.manage')
         || $user->hasPermission('whistleblower.view_all')) return true;

        // Assigned investigators see only their own cases — segregation of duty.
        return $user->hasPermission('whistleblower.investigate')
            && $report->assigned_investigator_id === $user->id;
    }

    public function triage(User $user): bool
    {
        return $user->hasPermission('whistleblower.investigate');
    }

    public function act(User $user, WhistleblowerReport $report): bool
    {
        if ($user->hasPermission('whistleblower.manage')) return true;
        return $user->hasPermission('whistleblower.investigate')
            && $report->assigned_investigator_id === $user->id;
    }

    /** Destruction reserved to super_admin via permission. */
    public function delete(User $user, WhistleblowerReport $report): bool
    {
        return $user->hasPermission('whistleblower.manage');
    }
}
