<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FeeProduct;
use App\Models\User;

/**
 * Gates the fee catalog. Read is open to anyone who needs to consult
 * what fees can be billed; write is finance-only.
 *
 * `fee_catalog.view`    — browse the catalog
 * `fee_catalog.manage`  — create / update / retire fee products
 */
class FeeProductPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('fee_catalog.view') || $user->hasPermission('fee_catalog.manage');
    }

    public function view(User $user, FeeProduct $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('fee_catalog.manage');
    }

    public function update(User $user, FeeProduct $product): bool
    {
        return $user->hasPermission('fee_catalog.manage');
    }

    public function delete(User $user, FeeProduct $product): bool
    {
        return $user->hasPermission('fee_catalog.manage');
    }
}
