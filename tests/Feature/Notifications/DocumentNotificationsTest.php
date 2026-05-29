<?php

use App\Events\DocumentSigned;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentRoute;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DocumentSignedNotification;
use App\Services\DocumentService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Bus::fake([SendSmsJob::class]);
});

it('dispatches DocumentSigned when DocumentService saves a signature annotation', function () {
    Event::fake([DocumentSigned::class]);

    $owner = User::factory()->create(['role' => 'employee']);
    $signer = User::factory()->create(['role' => 'employee']);
    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    app(DocumentService::class)->saveAnnotation($doc, null, $signer, [
        'type'   => 'signature',
        'page'   => 1,
        'x_pct'  => 10.0,
        'y_pct'  => 20.0,
        'data'   => ['kind' => 'inline'],
    ]);

    Event::assertDispatched(DocumentSigned::class);
});

it('does NOT dispatch DocumentSigned for non-signature annotations', function () {
    Event::fake([DocumentSigned::class]);

    $owner = User::factory()->create(['role' => 'employee']);
    $signer = User::factory()->create(['role' => 'employee']);
    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    app(DocumentService::class)->saveAnnotation($doc, null, $signer, [
        'type'   => 'stamp',
        'page'   => 1,
        'x_pct'  => 10.0,
        'y_pct'  => 20.0,
        'data'   => ['kind' => 'inline'],
    ]);

    Event::assertNotDispatched(DocumentSigned::class);
});

it('notifies the document owner with SMS when DocumentSigned fires (owner has phone via Employee)', function () {
    $owner = User::factory()->create(['role' => 'employee']);
    Employee::factory()->for($owner, 'user')->state(['phone' => '+233200000099'])->create();

    $signer = User::factory()->create(['role' => 'employee']);
    $doc = Document::factory()->for($owner, 'owner')->create();
    $annotation = DocumentAnnotation::factory()
        ->for($doc, 'document')
        ->for($signer, 'user')
        ->state(['type' => 'signature'])
        ->create();

    event(new DocumentSigned($doc, $annotation));

    Notification::assertSentTo($owner, DocumentSignedNotification::class);
    Bus::assertDispatchedTimes(SendSmsJob::class, 1);
});

it('also notifies the next signer in the routing workflow when DocumentSigned fires', function () {
    $owner = User::factory()->create(['role' => 'employee']);
    $signer = User::factory()->create(['role' => 'employee']);
    $nextSigner = User::factory()->create(['role' => 'employee']);
    $doc = Document::factory()->for($owner, 'owner')->create();

    $currentRoute = DocumentRoute::factory()
        ->for($doc, 'document')
        ->state(['sequence' => 1, 'to_user_id' => $signer->id])
        ->create();
    $nextRoute = DocumentRoute::factory()
        ->for($doc, 'document')
        ->state(['sequence' => 2, 'to_user_id' => $nextSigner->id])
        ->create();

    $annotation = DocumentAnnotation::factory()
        ->for($doc, 'document')
        ->for($signer, 'user')
        ->state(['type' => 'signature', 'route_id' => $currentRoute->id])
        ->create();

    event(new DocumentSigned($doc, $annotation));

    Notification::assertSentTo($owner, DocumentSignedNotification::class);
    Notification::assertSentTo($nextSigner, DocumentSignedNotification::class);
});
