<?php

namespace App\Policies;

use App\Models\DataSubjectRequest;
use App\Models\User;

class DataSubjectRequestPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /** DPO queue view. */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('privacy.fulfill');
    }

    /** Subject can always see own requests; DPO can see all. */
    public function view(User $user, DataSubjectRequest $req): bool
    {
        if ($user->hasPermission('privacy.fulfill')) return true;
        return $req->subject_user_id === $user->id;
    }

    public function submit(User $user): bool
    {
        return true; // every authenticated user has this right
    }

    public function fulfill(User $user, DataSubjectRequest $req): bool
    {
        return $user->hasPermission('privacy.fulfill');
    }

    /** Erasure is irreversible — gate it strictly. */
    public function erase(User $user, DataSubjectRequest $req): bool
    {
        return $user->hasPermission('privacy.erase');
    }

    public function withdraw(User $user, DataSubjectRequest $req): bool
    {
        return $req->subject_user_id === $user->id;
    }

    public function downloadExport(User $user, DataSubjectRequest $req): bool
    {
        // Only the subject can download their own export; DPO can re-generate but not download via this path.
        return $req->subject_user_id === $user->id && $req->export_path !== null;
    }
}
