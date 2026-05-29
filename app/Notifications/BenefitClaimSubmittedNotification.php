<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BenefitClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BenefitClaimSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly BenefitClaim $claim) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $claimant = $this->claim->enrolment?->employee?->user?->name ?? 'A member';
        return [
            'kind'    => 'benefit_claim_submitted',
            'message' => "{$claimant} has submitted a benefit claim.",
            'link'    => "/benefits/claims/{$this->claim->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $claimant = $this->claim->enrolment?->employee?->user?->name ?? 'A member';
        return (new MailMessage())
            ->subject('Benefit claim submitted for review')
            ->line("{$claimant} has submitted a benefit claim.")
            ->action('Review claim', url("/benefits/claims/{$this->claim->id}"));
    }
}
