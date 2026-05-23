<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Str;

/** Helper: create a Draft document owned by $owner without going through the upload pipeline. */
function makeDraftDoc(User $owner, array $attrs = []): Document {
    $doc = Document::create(array_merge([
        'uuid'            => (string) Str::uuid(),
        'ref_no'          => 'DOC-TEST-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
        'title'           => 'Test Doc',
        'description'     => null,
        'owner_id'        => $owner->id,
        'status'          => 'draft',
        'confidentiality' => 'internal',
    ], $attrs));
    DocumentVersion::create([
        'document_id'   => $doc->id,
        'version_no'    => 1,
        'storage_path'  => 'fake.pdf',
        'original_name' => 'fake.pdf',
        'mime'          => 'application/pdf',
        'size'          => 100,
        'uploaded_by'   => $owner->id,
        'uploaded_at'   => now(),
        'sha256'        => str_repeat('0', 64),
    ]);
    $doc->update(['current_version_id' => $doc->versions()->first()->id]);
    return $doc->fresh();
}

it('owner can update metadata on a Draft document', function () {
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $doc = makeDraftDoc($owner, ['title' => 'Old Title']);

    $this->actingAs($owner)
        ->patch(route('documents.update', $doc->uuid), [
            'title'           => 'New Title',
            'description'     => 'Now with description',
            'confidentiality' => 'confidential',
        ])
        ->assertRedirect();

    expect($doc->fresh()->title)->toBe('New Title');
    expect($doc->fresh()->confidentiality?->value)->toBe('confidential');
    $this->assertDatabaseHas('document_events', ['type' => 'updated', 'document_id' => $doc->id]);
});

it('non-owner cannot update', function () {
    $owner   = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $stranger = User::factory()->create(['permissions' => ['documents.view']]);
    $doc = makeDraftDoc($owner);

    $this->actingAs($stranger)
        ->patch(route('documents.update', $doc->uuid), ['title' => 'Hijack'])
        ->assertForbidden();
});

it('owner cannot update a non-Draft document', function () {
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $doc = makeDraftDoc($owner);
    $doc->update(['status' => 'in_review']);

    $this->actingAs($owner)
        ->patch(route('documents.update', $doc->uuid), ['title' => 'Too late'])
        ->assertForbidden();
});
