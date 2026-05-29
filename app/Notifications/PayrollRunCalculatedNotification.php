<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayrollRunCalculatedNotification extends Notification implements ShouldQueue
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
            'kind'    => 'payroll_run_calculated',
            'message' => "Payroll run for {$this->run->periodLabel()} has finished calculating.",
            'link'    => "/payroll/runs/{$this->run->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Payroll run calculated — {$this->run->periodLabel()}")
            ->line("The payroll run for {$this->run->periodLabel()} has finished calculating and is ready for review.")
            ->action('View run', url("/payroll/runs/{$this->run->id}"));
    }
}
