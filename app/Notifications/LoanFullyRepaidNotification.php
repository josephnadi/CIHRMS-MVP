<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LoanAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoanFullyRepaidNotification extends Notification implements ShouldQueue
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
            'kind'    => 'loan_repaid',
            'message' => "Your loan {$this->loan->reference} is fully repaid.",
            'link'    => "/loans/{$this->loan->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your loan is fully repaid')
            ->line("Your loan {$this->loan->reference} of GHS {$this->loan->principal} is now fully repaid.")
            ->line('Thank you for completing your repayment schedule.')
            ->action('View loan', url("/loans/{$this->loan->id}"));
    }

    public function toSmsBody(mixed $notifiable): string
    {
        return "Your loan {$this->loan->reference} is fully repaid. Thank you.";
    }
}
