<?php

namespace App\Policies;

use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;

/**
 * Documents authorisation policy.
 *
 * Notes:
 * - Uses the project's custom RBAC (\App\Models\User::hasPermission()) rather
 *   than Spatie's hasPermissionTo(), since this codebase ships its own
 *   permissions/roles tables and a Gate::before fallthrough in AppServiceProvider.
 * - "manage" permission is the org-wide override (super_admin / hr_admin grade).
 * - "view" widens to anyone who has been routed the document (from or to),
 *   plus the owner.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('documents.view');
    }

    public function view(User $user, Document $doc): bool
    {
        if ($user->hasPermission('documents.manage')) {
            return true;
        }
        if ($doc->owner_id === $user->id) {
            return true;
        }

        if ($doc->routes()->where('to_user_id', $user->id)->exists()
            || $doc->routes()->where('from_user_id', $user->id)->exists()) {
            return true;
        }

        // Documents v2 — Phase 1: shared-with-me widening. Honour user, dept,
        // and org-wide audiences, and ignore expired shares.
        $departmentId = $user->employee?->department_id;

        return $doc->shares()
            ->where(function ($q) use ($user, $departmentId) {
                $q->where(function ($a) use ($user) {
                    $a->where('audience_type', 'user')->where('audience_id', $user->id);
                })
                ->orWhere(function ($a) use ($departmentId) {
                    $a->where('audience_type', 'department')->where('audience_id', $departmentId);
                })
                ->orWhere('audience_type', 'organization');
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('documents.create');
    }

    public function update(User $user, Document $doc): bool
    {
        return $doc->owner_id === $user->id && $doc->status === DocumentStatus::Draft;
    }

    public function delete(User $user, Document $doc): bool
    {
        if ($user->hasPermission('documents.manage')) {
            return true;
        }

        return $doc->owner_id === $user->id && $doc->status === DocumentStatus::Draft;
    }

    public function route(User $user, Document $doc): bool
    {
        return $doc->owner_id === $user->id && $doc->status === DocumentStatus::Draft;
    }

    public function withdraw(User $user, Document $doc): bool
    {
        if ($user->hasPermission('documents.manage')) {
            return true;
        }

        return $doc->owner_id === $user->id && $doc->status === DocumentStatus::InReview;
    }

    public function annotate(User $user, Document $doc): bool
    {
        if ($doc->owner_id === $user->id && $doc->status === DocumentStatus::Draft) {
            return true;
        }

        return $doc->routes()
            ->where('to_user_id', $user->id)
            ->where('status', DocumentRouteStatus::InProgress->value)
            ->exists();
    }

    public function act(User $user, Document $doc, DocumentRoute $route): bool
    {
        return $route->to_user_id === $user->id
            && $route->status === DocumentRouteStatus::InProgress
            && $route->document_id === $doc->id;
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('documents.manage');
    }

    /**
     * Owner or any user with documents.manage may create / revoke shares.
     * Organization-scope shares require the documents.share_organization
     * permission in addition to ownership (enforced in the controller layer
     * so the policy stays scope-agnostic).
     */
    public function share(User $user, Document $doc): bool
    {
        return $doc->owner_id === $user->id || $user->hasPermission('documents.manage');
    }
}
