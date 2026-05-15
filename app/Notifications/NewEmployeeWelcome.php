<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewEmployeeWelcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Employee $employee) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to CIHRM Ghana')
            ->greeting("Welcome, {$notifiable->name}!")
            ->line("Your employee account has been created. Your staff ID is {$this->employee->employee_no}.")
            ->line("Position: {$this->employee->position}")
            ->action('Access Your Portal', route('dashboard'))
            ->line('If you have any questions, contact HR.');
    }
}
