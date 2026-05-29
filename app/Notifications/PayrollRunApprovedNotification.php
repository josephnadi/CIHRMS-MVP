<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayrollRunApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PayrollRun $run) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'    => 'payroll_run_approved',
            'message' => "Payroll run for {$this->run->periodLabel()} has been approved.",
            'link'    => "/payroll/runs/{$this->run->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Payroll run approved — {$this->run->periodLabel()}")
            ->line("The payroll run for {$this->run->periodLabel()} has been approved and is ready for disbursement.")
            ->action('View run', url("/payroll/runs/{$this->run->id}"));
    }
}
