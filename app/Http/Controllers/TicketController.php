<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ticket\StoreTicketRequest;
use App\Http\Requests\Ticket\UpdateTicketStatusRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Tickets/Index', [
            'tickets'      => TicketResource::collection($this->tickets->list($request)),
            'staff'        => $this->supportStaff(),
            'filters'      => $request->only(['status', 'priority', 'assigned_to', 'overdue', 'search']),
            'activeModule' => 'tickets',
        ]);
    }

    public function show(Ticket $ticket): Response
    {
        return Inertia::render('Tickets/Show', [
            'ticket'       => new TicketResource($this->tickets->find($ticket->id)),
            'staff'        => $this->supportStaff(),
            'activeModule' => 'tickets',
        ]);
    }

    private function supportStaff(): array
    {
        return User::whereIn('role', ['it_support', 'hr_admin', 'super_admin', 'manager'])
            ->select(['id', 'name', 'role'])
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $this->tickets->create($request);

        return back()->with('success', 'Ticket created successfully.');
    }

    public function updateStatus(UpdateTicketStatusRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->tickets->updateStatus($request, $ticket);

        return back()->with('success', 'Ticket updated.');
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        $ticket->delete();

        return back()->with('success', 'Ticket deleted.');
    }
}
