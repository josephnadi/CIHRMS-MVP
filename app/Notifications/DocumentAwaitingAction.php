<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\DocumentRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentAwaitingAction extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Document $document, public DocumentRoute $route) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Document awaiting your action: {$this->document->ref_no}")
            ->line("'{$this->document->title}' is awaiting your action.")
            ->action('Open document', url("/documents/{$this->document->uuid}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'document_id' => $this->document->id,
            'document_uuid' => $this->document->uuid,
            'ref_no'      => $this->document->ref_no,
            'title'       => $this->document->title,
            'route_id'    => $this->route->id,
            'action'      => $this->route->action_required->value,
        ];
    }
}
