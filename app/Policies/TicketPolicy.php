<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('tickets.create')
            || $user->hasPermission('tickets.manage');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->hasPermission('tickets.manage'))                 return true;
        if ($ticket->assigned_to === $user->id)                     return true;
        return $ticket->employee?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('tickets.create');
    }

    public function update(User $user, Ticket $ticket): bool
    {
        if ($user->hasPermission('tickets.manage')) return true;
        return $ticket->assigned_to === $user->id;
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('tickets.manage');
    }
}
