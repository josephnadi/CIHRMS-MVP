<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OffboardingCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OffboardingInitiatedNotification extends Notification implements ShouldQueue
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
        $isDeparting = $notifiable?->id === $this->case->employee?->user_id;
        return [
            'kind'    => 'offboarding_initiated',
            'message' => $isDeparting
                ? "Your offboarding has been initiated."
                : "Offboarding initiated for {$name}.",
            'link'    => "/offboarding/{$this->case->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $name = $this->case->employee?->user?->name ?? 'An employee';
        $isDeparting = $notifiable?->id === $this->case->employee?->user_id;

        $subject = $isDeparting ? 'Your offboarding has been initiated' : "Offboarding initiated — {$name}";
        $line = $isDeparting
            ? 'Your offboarding case has been opened. Please complete the clearance items assigned to you.'
            : "Offboarding has been initiated for {$name}. Please review pending clearance items.";

        return (new MailMessage())
            ->subject($subject)
            ->line($line)
            ->action('View offboarding case', url("/offboarding/{$this->case->id}"));
    }
}
