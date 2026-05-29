<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LoanAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoanDisbursedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LoanAccount $loan) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'loan_disbursed',
            'message' => $this->isApplicant($notifiable)
                ? "Your loan of GHS {$this->loan->principal} has been disbursed."
                : "Loan {$this->loan->reference} has been disbursed (GHS {$this->loan->principal}).",
            'link'    => "/loans/{$this->loan->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $applicant = $this->isApplicant($notifiable);
        $subject = $applicant ? 'Your loan has been disbursed' : 'Loan disbursement processed';
        $line = $applicant
            ? "Your loan of GHS {$this->loan->principal} has been disbursed to your account."
            : "Loan {$this->loan->reference} of GHS {$this->loan->principal} has been disbursed.";

        return (new MailMessage())
            ->subject($subject)
            ->line($line)
            ->line("Reference: {$this->loan->reference}")
            ->action('View loan', url("/loans/{$this->loan->id}"));
    }

    public function toSmsBody(mixed $notifiable): string
    {
        return "Your loan {$this->loan->reference} (GHS {$this->loan->principal}) has been disbursed.";
    }

    private function isApplicant(mixed $notifiable): bool
    {
        return $notifiable?->id === $this->loan->employee?->user_id;
    }
}
