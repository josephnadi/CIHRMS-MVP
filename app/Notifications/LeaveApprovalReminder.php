<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveApprovalReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LeaveRequest $leave) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pending Leave Approval Reminder')
            ->line("A leave request from {$this->leave->employee?->user?->name} has been pending for more than 3 days.")
            ->line("Type: {$this->leave->type->label()} | Duration: {$this->leave->durationInDays()} day(s)")
            ->action('Review Now', route('leave.index'));
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'message'  => "Leave request from {$this->leave->employee?->user?->name} is still pending approval.",
            'leave_id' => $this->leave->id,
        ];
    }
}
