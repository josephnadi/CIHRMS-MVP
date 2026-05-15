<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LeaveRequest $leave) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Leave Request {$this->leave->status->label()}")
            ->line("Your {$this->leave->type->label()} leave has been {$this->leave->status->label()}.")
            ->action('View Request', route('leave.index'));
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'message'  => "Your {$this->leave->type->label()} leave request was {$this->leave->status->label()}.",
            'leave_id' => $this->leave->id,
        ];
    }
}
