<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Http\Requests\Ticket\StoreTicketRequest;
use App\Http\Requests\Ticket\UpdateTicketStatusRequest;
use App\Models\Ticket;
use App\Notifications\TicketResolved;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class TicketService
{
    public function create(StoreTicketRequest $request): Ticket
    {
        $data = $request->validated();
        $data['employee_id'] ??= $request->user()?->employee?->id;

        $ticket = Ticket::create($data);

        event(new TicketCreated($ticket, $request->user()));

        return $ticket;
    }

    public function updateStatus(UpdateTicketStatusRequest $request, Ticket $ticket): Ticket
    {
        $previousStatus = $ticket->status;
        $updates        = [];

        if ($request->filled('status')) {
            $status = TicketStatus::from($request->validated('status'));
            $updates['status'] = $status;
            if ($status === TicketStatus::Resolved) {
                $updates['resolved_at'] = now();
            }
        }

        if ($request->filled('priority')) {
            $updates['priority'] = $request->validated('priority');
        }

        // assigned_to may be explicitly null (unassign) — use has() not filled().
        if ($request->has('assigned_to')) {
            $updates['assigned_to'] = $request->validated('assigned_to');
        }

        if (! empty($updates)) {
            $ticket->update($updates);
        }

        // Notify the requester when the ticket reaches a terminal status
        // (Resolved or Closed) — but only on the transition itself, never
        // re-fire if the status was already there.
        if (isset($updates['status'])) {
            $newStatus  = $updates['status'];
            $isTerminal = in_array($newStatus, [TicketStatus::Resolved, TicketStatus::Closed], true);
            $wasTerminal = in_array($previousStatus, [TicketStatus::Resolved, TicketStatus::Closed], true);

            if ($isTerminal && ! $wasTerminal) {
                $requester = $ticket->loadMissing('employee.user')->employee?->user;
                $resolver  = $request->user();
                // Don't ping the resolver if they happen to be the requester too.
                if ($requester && $requester->id !== $resolver?->id) {
                    $requester->notify(new TicketResolved(
                        $ticket->fresh(),
                        $newStatus,
                        $resolver?->name,
                    ));
                }
            }
        }

        return $ticket;
    }

    public function list(Request $request): LengthAwarePaginator
    {
        return Ticket::with(['employee.user', 'assignedTo'])
            ->when($request->status,      fn ($q, $v) => $q->where('status', $v))
            ->when($request->priority,    fn ($q, $v) => $q->where('priority', $v))
            ->when($request->assigned_to, fn ($q, $v) => $q->where('assigned_to', $v))
            // Cross-driver search: ilike is Postgres-only; LOWER() + LIKE works
            // on SQLite (test runner), MySQL, and Postgres alike.
            ->when($request->search, fn ($q, $v) => $q->where(fn ($s) => $s
                ->whereRaw('LOWER(title) LIKE ?', ['%'.strtolower((string) $v).'%'])
                ->orWhereRaw('LOWER(description) LIKE ?', ['%'.strtolower((string) $v).'%'])))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('due_at', '<', now())
                ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value]))
            ->latest()
            ->paginate($request->per_page ?? 20)
            ->withQueryString();
    }

    public function find(int $id): Ticket
    {
        return Ticket::with(['employee.user', 'assignedTo'])->findOrFail($id);
    }
}
