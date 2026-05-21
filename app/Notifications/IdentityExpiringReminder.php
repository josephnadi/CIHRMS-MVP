<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\IdentityVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ghana Card verification is approaching its 12-month re-verification window.
 * Sent to the employee (so they can prepare their Ghana Card for the re-scan)
 * and copied to HR identity-verifiers via the database channel so the manager
 * dashboard surfaces upcoming renewals.
 */
class IdentityExpiringReminder extends Notification
{
    use Queueable;

    public function __construct(
        public readonly IdentityVerification $verification,
        public readonly int $daysRemaining,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiresOn = $this->verification->expires_at?->format('d M Y');

        return (new MailMessage())
            ->subject("Ghana Card re-verification due in {$this->daysRemaining} day(s)")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your Ghana Card verification on record expires on **{$expiresOn}**.")
            ->line("Please book a re-verification slot with HR to keep your payroll, benefits, and identity-linked services active.")
            ->action('Visit profile', url('/profile'))
            ->line('Failure to re-verify within the deadline may pause payroll disbursement under Act 750.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'                     => 'identity_expiring',
            'verification_id'          => $this->verification->id,
            'employee_id'              => $this->verification->employee_id,
            'expires_at'               => $this->verification->expires_at?->toIso8601String(),
            'days_remaining'           => $this->daysRemaining,
        ];
    }
}
