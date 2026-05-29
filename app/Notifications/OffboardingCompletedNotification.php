<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OffboardingCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OffboardingCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OffboardingCase $case) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $name = $this->case->employee?->user?->name ?? 'An employee';
        return [
            'kind'    => 'offboarding_completed',
            'message' => "Offboarding completed for {$name}.",
            'link'    => "/offboarding/{$this->case->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $name = $this->case->employee?->user?->name ?? 'An employee';
        return (new MailMessage())
            ->subject("Offboarding completed — {$name}")
            ->line("All clearance items have been completed for {$name}.")
            ->action('View case', url("/offboarding/{$this->case->id}"));
    }
}
