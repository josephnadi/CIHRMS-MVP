<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Document;
use App\Models\DocumentAnnotation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentSignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Document $document,
        public readonly DocumentAnnotation $annotation,
    ) {}

    public function via(mixed $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $signer = $this->annotation->user?->name ?? 'A signer';
        return [
            'kind'    => 'document_signed',
            'message' => "{$signer} signed '{$this->document->title}'.",
            'link'    => "/documents/{$this->document->id}",
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $signer = $this->annotation->user?->name ?? 'A signer';
        return (new MailMessage())
            ->subject("'{$this->document->title}' has been signed")
            ->line("{$signer} signed '{$this->document->title}'.")
            ->action('View document', url("/documents/{$this->document->id}"));
    }

    public function toSmsBody(mixed $notifiable): string
    {
        $signer = $this->annotation->user?->name ?? 'A signer';
        return "{$signer} signed '{$this->document->title}'.";
    }
}
