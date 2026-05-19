<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentCompletedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Document $document, public bool $rejected = false) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verb = $this->rejected ? 'rejected' : 'completed';
        return (new MailMessage)
            ->subject("Document {$verb}: {$this->document->ref_no}")
            ->line("'{$this->document->title}' has been {$verb}.")
            ->action('Open document', url("/documents/{$this->document->uuid}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'document_id'   => $this->document->id,
            'document_uuid' => $this->document->uuid,
            'ref_no'        => $this->document->ref_no,
            'title'         => $this->document->title,
            'rejected'      => $this->rejected,
        ];
    }
}
