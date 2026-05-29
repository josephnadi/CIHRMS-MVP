<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\DocumentSigned;
use App\Models\DocumentRoute;
use App\Models\User;
use App\Notifications\DocumentSignedNotification;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendDocumentNotifications implements ShouldQueue
{
    public function __construct(private readonly SmsDispatcher $sms) {}

    public function handle(object $event): void
    {
        if ($event instanceof DocumentSigned) {
            $this->onSigned($event);
        }
    }

    private function onSigned(DocumentSigned $event): void
    {
        $document   = $event->document;
        $annotation = $event->annotation;
        $owner      = $document->owner;
        $notification = new DocumentSignedNotification($document, $annotation);

        if ($owner) {
            $owner->notify($notification);
            $phone = $owner->employee?->phone;
            if ($phone) {
                $this->sms->send(
                    toPhone:     $phone,
                    body:        $notification->toSmsBody($owner),
                    contextType: 'document',
                    contextId:   $document->id,
                );
            }
        }

        // Notify the next signer in the routing workflow (if any).
        $currentRoute = $annotation->route_id
            ? DocumentRoute::find($annotation->route_id)
            : null;

        if ($currentRoute) {
            $nextRoute = DocumentRoute::query()
                ->where('document_id', $document->id)
                ->where('sequence', '>', $currentRoute->sequence)
                ->orderBy('sequence')
                ->first();

            $nextSigner = $nextRoute?->toUser;
            if ($nextSigner && $nextSigner->id !== $owner?->id) {
                $nextSigner->notify($notification);
            }
        }
    }
}
