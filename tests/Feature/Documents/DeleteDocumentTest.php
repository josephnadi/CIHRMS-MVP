<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Str;

function makeDeletableDoc(User $owner, array $attrs = []): Document {
    $doc = Document::create(array_merge([
        'uuid'            => (string) Str::uuid(),
        'ref_no'          => 'DOC-DEL-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
        'title'           => 'Deletable Doc',
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

it('owner can soft-delete their own Draft document', function () {
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $doc = makeDeletableDoc($owner);

    $this->actingAs($owner)
        ->delete(route('documents.destroy', $doc->uuid))
        ->assertRedirect(route('documents.index'));

    expect($doc->fresh()->trashed())->toBeTrue();
    $this->assertDatabaseHas('document_events', ['type' => 'deleted', 'document_id' => $doc->id]);
});

it('non-owner without manage cannot delete', function () {
    $owner    = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $stranger = User::factory()->create(['permissions' => ['documents.view']]);
    $doc = makeDeletableDoc($owner);

    $this->actingAs($stranger)
        ->delete(route('documents.destroy', $doc->uuid))
        ->assertForbidden();

    expect($doc->fresh()->trashed())->toBeFalse();
});

it('user with documents.manage can delete any document, regardless of status', function () {
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $admin = User::factory()->create(['permissions' => ['documents.view', 'documents.manage']]);
    $doc = makeDeletableDoc($owner, ['status' => 'in_review']);

    $this->actingAs($admin)
        ->delete(route('documents.destroy', $doc->uuid))
        ->assertRedirect();

    expect($doc->fresh()->trashed())->toBeTrue();
});

it('owner cannot delete once the doc is no longer Draft (without manage)', function () {
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $doc = makeDeletableDoc($owner, ['status' => 'in_review']);

    $this->actingAs($owner)
        ->delete(route('documents.destroy', $doc->uuid))
        ->assertForbidden();
});
