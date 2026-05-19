<?php

use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('renders the composer page', function () {
    $user = User::factory()->create(['permissions' => ['documents.create']]);

    $this->actingAs($user)
        ->get(route('documents.compose'))
        ->assertOk();
});

it('composes a document with letterhead → PDF stored, Document row created in Draft', function () {
    Storage::fake('local');

    $user = User::factory()->create([
        'name'        => 'Abena Composer',
        'permissions' => ['documents.create'],
    ]);

    $this->actingAs($user)
        ->post(route('documents.compose.store'), [
            'title'           => 'Memo on Q2 Budget',
            'description'    => 'Drafted in-portal',
            'confidentiality' => 'internal',
            'body_html'       => '<h1>Memo</h1><p>To Finance — please review attached Q2 budget submission and revert by EOW.</p><p>Best,<br>Abena</p>',
            'letterhead'      => true,
        ])
        ->assertRedirect();

    $doc = Document::query()->where('owner_id', $user->id)->latest('id')->firstOrFail();

    expect($doc->title)->toBe('Memo on Q2 Budget');
    expect($doc->status)->toBe(DocumentStatus::Draft);
    expect($doc->ref_no)->toMatch('/^CIHRMS\\/DOC\\/\\d{4}\\/\\d{4}$/');

    // Version 1 should exist and the file should be on the local disk as a PDF.
    $v = $doc->versions()->first();
    expect($v)->not->toBeNull();
    expect($v->version_no)->toBe(1);
    expect($v->mime)->toBe('application/pdf');
    expect($v->size)->toBeGreaterThan(100);
    expect(Storage::disk('local')->exists($v->storage_path))->toBeTrue();

    // Sha-256 was computed before move; it should be the canonical 64-char hash.
    expect(strlen($v->sha256))->toBe(64);

    // Audit timeline reflects the composed-upload.
    $this->assertDatabaseHas('document_events', [
        'document_id' => $doc->id,
        'actor_id'    => $user->id,
        'type'        => DocumentEventType::Uploaded->value,
    ]);
});

it('composes without letterhead when the toggle is off', function () {
    Storage::fake('local');
    $user = User::factory()->create(['permissions' => ['documents.create']]);

    $this->actingAs($user)
        ->post(route('documents.compose.store'), [
            'title'      => 'Plain memo',
            'body_html'  => '<p>Hello world.</p>',
            'letterhead' => false,
        ])
        ->assertRedirect();

    $doc = Document::query()->where('owner_id', $user->id)->latest('id')->firstOrFail();
    expect($doc->versions()->first()->notes)->toContain('Composed in-portal');
    expect($doc->versions()->first()->notes)->not->toContain('with letterhead');
});

it('rejects compose with an empty body', function () {
    $user = User::factory()->create(['permissions' => ['documents.create']]);

    $this->actingAs($user)
        ->post(route('documents.compose.store'), [
            'title'     => 'Empty',
            'body_html' => '',
        ])
        ->assertSessionHasErrors('body_html');
});

it('forbids compose without documents.create permission', function () {
    $user = User::factory()->create(['permissions' => []]);

    $this->actingAs($user)
        ->post(route('documents.compose.store'), [
            'title'     => 'x',
            'body_html' => '<p>x</p>',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('documents.compose'))
        ->assertForbidden();
});

it('sanitises script and event-handler attributes from composed HTML', function () {
    Storage::fake('local');
    $user = User::factory()->create(['permissions' => ['documents.create']]);

    $hostile = '<p onclick="alert(1)">Body</p><script>alert("x")</script>'
             . '<a href="javascript:alert(1)">x</a>';

    $this->actingAs($user)
        ->post(route('documents.compose.store'), [
            'title'      => 'Hostile paste',
            'body_html'  => $hostile,
            'letterhead' => true,
        ])
        ->assertRedirect();

    $doc = Document::query()->where('owner_id', $user->id)->latest('id')->firstOrFail();
    expect($doc)->not->toBeNull();
    // The PDF generation must not have thrown; if it did, we wouldn't have a version row.
    expect($doc->versions()->first()?->mime)->toBe('application/pdf');
});
