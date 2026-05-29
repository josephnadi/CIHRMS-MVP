<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LoanAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoanApprovedNotification extends Notification implements ShouldQueue
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
            'kind'    => 'loan_approved',
            'message' => $this->isApplicant($notifiable)
                ? "Your loan of GHS {$this->loan->principal} has been approved."
                : "{$this->loan->employee?->user?->name}'s loan of GHS {$this->loan->principal} has been approved.",
            'link'    => "/loans/{$this->loan->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $applicant = $this->isApplicant($notifiable);
        $subject = $applicant ? 'Your loan has been approved' : 'Loan approval notice';
        $line = $applicant
            ? "Your loan request of GHS {$this->loan->principal} has been approved."
            : "{$this->loan->employee?->user?->name}'s loan request of GHS {$this->loan->principal} has been approved.";

        return (new MailMessage())
            ->subject($subject)
            ->line($line)
            ->line("Reference: {$this->loan->reference}")
            ->action('View loan', url("/loans/{$this->loan->id}"));
    }

    private function isApplicant(mixed $notifiable): bool
    {
        return $notifiable?->id === $this->loan->employee?->user_id;
    }
}
