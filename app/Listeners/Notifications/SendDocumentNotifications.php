<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\DocumentSigned;
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
        $owner = $event->document->owner;
        if (! $owner) {
            return;
        }

        $notification = new DocumentSignedNotification($event->document, $event->annotation);
        $owner->notify($notification);

        $phone = $owner->employee?->phone;
        if ($phone) {
            $this->sms->send(
                toPhone:     $phone,
                body:        $notification->toSmsBody($owner),
                contextType: 'document',
                contextId:   $event->document->id,
            );
        }
    }
}
