<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Enrolment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ComplianceTrainingDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Enrolment $enrolment) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $course = $this->enrolment->course?->title ?? 'a mandatory course';
        $due    = optional($this->enrolment->due_at)->toDateString() ?? '';

        return [
            'kind'    => 'compliance_training_due',
            'message' => "Mandatory training “{$course}” is overdue (was due {$due}).",
            'link'    => '/learning/my',
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $course = $this->enrolment->course?->title ?? 'a mandatory course';
        $due    = optional($this->enrolment->due_at)->toDateString() ?? '';

        return (new MailMessage())
            ->subject("Mandatory training overdue — {$course}")
            ->line("Your mandatory training “{$course}” is overdue (was due {$due}).")
            ->action('Go to My Learning', url('/learning/my'));
    }
}
