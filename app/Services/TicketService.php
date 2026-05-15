<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Http\Requests\Ticket\StoreTicketRequest;
use App\Http\Requests\Ticket\UpdateTicketStatusRequest;
use App\Models\Ticket;
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
        $status = TicketStatus::from($request->validated('status'));

        $updates = ['status' => $status];

        if ($request->has('assigned_to')) {
            $updates['assigned_to'] = $request->validated('assigned_to');
        }

        if ($status === TicketStatus::Resolved) {
            $updates['resolved_at'] = now();
        }

        $ticket->update($updates);

        return $ticket;
    }

    public function list(Request $request): LengthAwarePaginator
    {
        return Ticket::with(['employee.user', 'assignedTo'])
            ->when($request->status,      fn ($q, $v) => $q->where('status', $v))
            ->when($request->priority,    fn ($q, $v) => $q->where('priority', $v))
            ->when($request->assigned_to, fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($request->search, fn ($q, $v) => $q->where(fn ($s) => $s
                ->where('title', 'ilike', "%$v%")
                ->orWhere('description', 'ilike', "%$v%")))
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
