<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ArInvoice;
use App\Models\ArReceipt;
use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a Member when an `ArReceipt` against their account is posted.
 * Fires off the back of the `ReceiptProcessed` event the
 * `PaystackWebhookProcessor` dispatches.
 *
 * Channels (resolved in `via()`):
 *   - `mail` whenever the member has an email
 *   - `sms`  whenever the member has a phone
 *
 * The `sms` channel is a no-op Laravel channel that maps onto
 * `App\Services\Messaging\Sms\SmsDispatcher` — see `toSms()` below; the
 * dispatcher is invoked from the listener, not the Channel interface,
 * because the SMS layer pre-dates Laravel-channel infrastructure here.
 * In tests this becomes a `Notification::assertSentTo(...)` style check
 * against the notification class, with the SMS side dispatched through
 * the listener for unit-test visibility.
 */
class PaymentReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ArReceipt $receipt,
        /** @var array<int, ArInvoice>  */
        public readonly array $invoices = [],
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        $channels = [];
        if ($notifiable instanceof Member) {
            if (!empty($notifiable->email)) $channels[] = 'mail';
            // SMS dispatch lives in the listener; the notification just
            // describes intent. Tests assert the notification was sent.
        } elseif (!empty($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $amount   = number_format((float) $this->receipt->amount, 2);
        $currency = $this->receipt->currency ?? 'GHS';
        $ref      = $this->receipt->reference;

        $mail = (new MailMessage)
            ->subject("Payment received — {$currency} {$amount}")
            ->greeting('Thank you for your payment.')
            ->line("We have received {$currency} {$amount} (receipt {$ref}).");

        foreach ($this->invoices as $inv) {
            $mail->line("Applied to invoice {$inv->reference}: {$currency} " . number_format((float) $inv->total, 2));
        }

        return $mail
            ->line('Your fee statement has been updated.')
            ->action('View statement', route('portal.statements'));
    }

    /**
     * Plain-text SMS body. The listener pulls this and hands it to
     * `SmsDispatcher::send()`. Keep within ~140 chars to stay inside
     * one SMS segment for cost reasons.
     */
    public function toSmsBody(): string
    {
        $amount   = number_format((float) $this->receipt->amount, 2);
        $currency = $this->receipt->currency ?? 'GHS';
        return "CIHRM: payment received, {$currency} {$amount}. Receipt {$this->receipt->reference}. Thank you.";
    }
}
