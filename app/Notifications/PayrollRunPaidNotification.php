<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollLine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayrollRunPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PayrollLine $line) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'payslip_available',
            'message' => "Your payslip for {$this->line->run?->periodLabel()} is available.",
            'link'    => "/payroll/lines/{$this->line->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Your payslip is available — {$this->line->run?->periodLabel()}")
            ->line("Your payslip for {$this->line->run?->periodLabel()} is now available.")
            ->line("Net pay: GHS {$this->line->net}")
            ->action('View payslip', url("/payroll/lines/{$this->line->id}"));
    }

    public function toSmsBody(mixed $notifiable): string
    {
        return "Your {$this->line->run?->periodLabel()} payslip is available. Net: GHS {$this->line->net}.";
    }
}
