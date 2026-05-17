<?php

namespace App\Notifications;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TicketResolved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketStatus $status,
        public readonly ?string $resolverName = null,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    public function toArray(mixed $notifiable): array
    {
        $verb = $this->status === TicketStatus::Resolved ? 'resolved' : 'closed';
        $by   = $this->resolverName ? " by {$this->resolverName}" : '';

        return [
            'message'   => "Ticket #{$this->ticket->id} \"{$this->ticket->title}\" has been {$verb}{$by}.",
            'ticket_id' => $this->ticket->id,
            'status'    => $this->status->value,
            'kind'      => 'ticket.completed',
        ];
    }
}
