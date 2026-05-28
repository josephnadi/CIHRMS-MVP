<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fires to users holding `messaging.manage` when a SendSmsJob exhausts all
 * retry attempts. Rate-limited (one per recipient per 15 minutes) to prevent
 * a provider outage from spamming admins.
 */
class SmsDispatchExhausted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $smsMessageId,
        public readonly string $toPhone,
        public readonly ?string $contextType,
        public readonly ?int $contextId,
        public readonly string $failureReason,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind'            => 'sms_exhausted',
            'message'         => "SMS to {$this->toPhone} failed after 3 retries: {$this->failureReason}",
            'sms_message_id'  => $this->smsMessageId,
            'context_type'    => $this->contextType,
            'context_id'      => $this->contextId,
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = url('/admin/messaging?status=failed');
        return (new MailMessage())
            ->subject('SMS dispatch failed after retries')
            ->line("An outbound SMS to {$this->toPhone} could not be delivered after 3 attempts.")
            ->line("Reason: {$this->failureReason}")
            ->when($this->contextType, fn ($m) => $m->line("Context: {$this->contextType}#{$this->contextId}"))
            ->action('Review failed messages', $url);
    }

    public static function for(SmsMessage $msg, \Throwable $cause): self
    {
        return new self(
            smsMessageId:  $msg->id,
            toPhone:       $msg->to_phone,
            contextType:   $msg->context_type,
            contextId:     $msg->context_id,
            failureReason: $cause->getMessage(),
        );
    }
}
