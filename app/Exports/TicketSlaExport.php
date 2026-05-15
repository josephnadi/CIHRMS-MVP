<?php

namespace App\Exports;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TicketSlaExport implements FromQuery, WithHeadings, WithMapping
{
    public function query()
    {
        return Ticket::where('status', TicketStatus::Resolved)->with('employee.user');
    }

    public function headings(): array
    {
        return ['Title', 'Priority', 'Opened', 'Due', 'Resolved At', 'SLA Met'];
    }

    public function map($ticket): array
    {
        $slaMet = $ticket->resolved_at && $ticket->due_at
            ? ($ticket->resolved_at->lte($ticket->due_at) ? 'Yes' : 'No')
            : 'N/A';

        return [
            $ticket->title,
            $ticket->priority,
            $ticket->created_at?->toDateTimeString(),
            $ticket->due_at?->toDateTimeString(),
            $ticket->resolved_at?->toDateTimeString(),
            $slaMet,
        ];
    }
}
