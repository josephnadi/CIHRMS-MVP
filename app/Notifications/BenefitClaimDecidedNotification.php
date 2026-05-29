<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BenefitClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BenefitClaimDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly BenefitClaim $claim) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'benefit_claim_decided',
            'message' => "Your benefit claim has been {$this->claim->status->value}.",
            'link'    => "/benefits/claims/{$this->claim->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your benefit claim has a decision')
            ->line("Your benefit claim has been {$this->claim->status->value}.")
            ->action('View claim', url("/benefits/claims/{$this->claim->id}"));
    }
}
