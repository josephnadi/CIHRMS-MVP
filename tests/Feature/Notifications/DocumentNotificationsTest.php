<?php

use App\Events\DocumentSigned;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\Document;
use App\Models\DocumentAnnotation;
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
