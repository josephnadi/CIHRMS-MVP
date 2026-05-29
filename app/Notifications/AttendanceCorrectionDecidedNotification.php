<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceCorrection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttendanceCorrectionDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AttendanceCorrection $correction) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'attendance_correction_decided',
            'message' => "Your attendance correction was {$this->correction->status->value}.",
            'link'    => "/attendance/corrections/{$this->correction->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Decision on your attendance correction')
            ->line("Your attendance correction was {$this->correction->status->value}.")
            ->when($this->correction->decision_notes, fn ($m) => $m->line("Notes: {$this->correction->decision_notes}"))
            ->action('View correction', url("/attendance/corrections/{$this->correction->id}"));
    }
}
