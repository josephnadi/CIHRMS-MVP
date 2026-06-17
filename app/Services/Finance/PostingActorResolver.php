<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\User;

/**
 * Resolves the user id to stamp on a journal entry's created_by/posted_by.
 * Order: explicit actor → authenticated user → configured system user →
 * first super_admin. Lets the posting engine work in queued/webhook/console
 * contexts where auth() is null, instead of stamping null.
 */
class PostingActorResolver
{
    public function resolveId(?User $actor = null): ?int
    {
        if ($actor !== null) {
            return $actor->id;
        }

        $authId = auth()->id();
        if ($authId !== null) {
            return $authId;
        }

        return $this->systemUserId();
    }

    private function systemUserId(): ?int
    {
        $configured = config('services.billing.system_user_id');
        if ($configured !== null && User::whereKey($configured)->exists()) {
            return (int) $configured;
        }

        return User::where('role', 'super_admin')->orderBy('id')->value('id');
    }
}
