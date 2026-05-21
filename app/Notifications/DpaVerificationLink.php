<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\DataSubjectRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to a public data-subject submitter so they can confirm they own
 * the email they submitted under. Without this verification step anyone
 * could file an Erasure request "as" someone else. The link expires only
 * when the request transitions out of `pending_verification` — there's no
 * explicit TTL because each request has a single one-time token.
 *
 * Routing-via-on-demand pattern is required because public subjects don't
 * have a User row to attach to; the service notifies an anonymous Notifiable
 * built from (email, name) via `Notification::route('mail', $email)->notify(...)`.
 */
class DpaVerificationLink extends Notification
{
    use Queueable;

    public function __construct(public readonly DataSubjectRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verifyUrl = url(route('dpa.verify', ['token' => $this->request->verification_token], absolute: false));
        $trackUrl  = url(route('dpa.track', [], absolute: false))
            . '?reference=' . urlencode($this->request->reference);

        return (new MailMessage())
            ->subject("Confirm your data-subject request {$this->request->reference}")
            ->greeting("Hello {$this->request->subject_full_name},")
            ->line('You submitted a data-subject request under the Ghana Data Protection Act 2012 (Act 843).')
            ->line("Reference: **{$this->request->reference}**")
            ->line('To confirm you submitted this request, click the button below. We will not start the 30-day statutory clock until verification completes.')
            ->action('Confirm my request', $verifyUrl)
            ->line('You can check the status at any time using your reference number:')
            ->line($trackUrl)
            ->line('If you did not submit this request, no action is required — the request will not proceed without verification.');
    }
}
