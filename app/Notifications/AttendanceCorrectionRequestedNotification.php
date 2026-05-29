<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceCorrection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttendanceCorrectionRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AttendanceCorrection $correction) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $requester = $this->correction->employee?->user?->name ?? 'An employee';
        return [
            'kind'    => 'attendance_correction_requested',
            'message' => "{$requester} requested an attendance correction.",
            'link'    => "/attendance/corrections/{$this->correction->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $requester = $this->correction->employee?->user?->name ?? 'An employee';
        return (new MailMessage())
            ->subject('Attendance correction awaiting approval')
            ->line("{$requester} requested an attendance correction.")
            ->line("Reason: {$this->correction->reason}")
            ->action('Review correction', url("/attendance/corrections/{$this->correction->id}"));
    }
}
