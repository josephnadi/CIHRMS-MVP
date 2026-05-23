<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Str;

function makeShareableDoc(User $owner, array $attrs = []): Document {
    $doc = Document::create(array_merge([
        'uuid'            => (string) Str::uuid(),
        'ref_no'          => 'DOC-SHARE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
        'title'           => 'Shareable Doc',
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

it('owner can share with an individual user; recipient can then view', function () {
    $owner     = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $recipient = User::factory()->create(['permissions' => ['documents.view']]);
    $doc = makeShareableDoc($owner);

    // Before share: recipient cannot view
    $this->actingAs($recipient)->get(route('documents.show', $doc->uuid))->assertForbidden();

    // Owner shares
    $this->actingAs($owner)
        ->post(route('documents.shares.store', $doc->uuid), [
            'audience_type' => 'user',
            'audience_id'   => $recipient->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('document_shares', [
        'document_id'   => $doc->id,
        'audience_type' => 'user',
        'audience_id'   => $recipient->id,
    ]);

    // After share: recipient can view
    $this->actingAs($recipient)->get(route('documents.show', $doc->uuid))->assertOk();
});

it('department share grants view to anyone in that department', function () {
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $dept  = Department::factory()->create();
    $member = User::factory()->create(['permissions' => ['documents.view']]);
    Employee::factory()->create(['user_id' => $member->id, 'department_id' => $dept->id]);

    $doc = makeShareableDoc($owner);

    $this->actingAs($owner)
        ->post(route('documents.shares.store', $doc->uuid), [
            'audience_type' => 'department',
            'audience_id'   => $dept->id,
        ])
        ->assertRedirect();

    $this->actingAs($member)->get(route('documents.show', $doc->uuid))->assertOk();
});

it('organization share grants view to any authenticated user with documents.view', function () {
    $owner = User::factory()->create([
        'permissions' => ['documents.view', 'documents.create', 'documents.share_organization'],
    ]);
    $anyone = User::factory()->create(['permissions' => ['documents.view']]);

    $doc = makeShareableDoc($owner);

    $this->actingAs($owner)
        ->post(route('documents.shares.store', $doc->uuid), [
            'audience_type' => 'organization',
        ])
        ->assertRedirect();

    $this->actingAs($anyone)->get(route('documents.show', $doc->uuid))->assertOk();
});

it('blocks org-wide share when owner lacks documents.share_organization', function () {
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $doc = makeShareableDoc($owner);

    $this->actingAs($owner)
        ->post(route('documents.shares.store', $doc->uuid), [
            'audience_type' => 'organization',
        ])
        ->assertForbidden();

    expect(DocumentShare::count())->toBe(0);
});

it('confidentiality guard: confidential doc cannot be shared with department', function () {
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $dept  = Department::factory()->create();
    $doc = makeShareableDoc($owner, ['confidentiality' => 'confidential']);

    $this->actingAs($owner)
        ->post(route('documents.shares.store', $doc->uuid), [
            'audience_type' => 'department',
            'audience_id'   => $dept->id,
        ])
        ->assertSessionHasErrors(['audience_type']);
});

it('confidentiality guard: restricted doc cannot be shared org-wide', function () {
    $owner = User::factory()->create([
        'permissions' => ['documents.view', 'documents.create', 'documents.share_organization'],
    ]);
    $doc = makeShareableDoc($owner, ['confidentiality' => 'restricted']);

    $this->actingAs($owner)
        ->post(route('documents.shares.store', $doc->uuid), [
            'audience_type' => 'organization',
        ])
        ->assertSessionHasErrors(['audience_type']);
});

it('revoking a share strips the recipient\'s view access', function () {
    $owner     = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $recipient = User::factory()->create(['permissions' => ['documents.view']]);
    $doc = makeShareableDoc($owner);

    $this->actingAs($owner)->post(route('documents.shares.store', $doc->uuid), [
        'audience_type' => 'user',
        'audience_id'   => $recipient->id,
    ]);
    $share = DocumentShare::firstOrFail();

    // Recipient can view
    $this->actingAs($recipient)->get(route('documents.show', $doc->uuid))->assertOk();

    // Owner revokes
    $this->actingAs($owner)
        ->delete(route('documents.shares.destroy', ['document' => $doc->uuid, 'share' => $share->id]))
        ->assertRedirect();

    $this->assertDatabaseMissing('document_shares', ['id' => $share->id]);

    // Recipient now blocked
    $this->actingAs($recipient)->get(route('documents.show', $doc->uuid))->assertForbidden();
});

it('expired share no longer grants view', function () {
    $owner     = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $recipient = User::factory()->create(['permissions' => ['documents.view']]);
    $doc = makeShareableDoc($owner);

    DocumentShare::create([
        'document_id'   => $doc->id,
        'audience_type' => 'user',
        'audience_id'   => $recipient->id,
        'granted_by'    => $owner->id,
        'granted_at'    => now()->subDay(),
        'expires_at'    => now()->subHour(),
    ]);

    $this->actingAs($recipient)->get(route('documents.show', $doc->uuid))->assertForbidden();
});

it('non-existent audience target is rejected (422)', function () {
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $doc = makeShareableDoc($owner);

    $this->actingAs($owner)
        ->post(route('documents.shares.store', $doc->uuid), [
            'audience_type' => 'user',
            'audience_id'   => 99999,
        ])
        ->assertSessionHasErrors(['audience_type']);
});

it('share is idempotent: granting the same (doc, audience) twice does not duplicate', function () {
    $owner     = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $recipient = User::factory()->create(['permissions' => ['documents.view']]);
    $doc = makeShareableDoc($owner);

    $this->actingAs($owner)->post(route('documents.shares.store', $doc->uuid), [
        'audience_type' => 'user', 'audience_id' => $recipient->id,
    ]);
    $this->actingAs($owner)->post(route('documents.shares.store', $doc->uuid), [
        'audience_type' => 'user', 'audience_id' => $recipient->id,
    ]);

    expect(DocumentShare::count())->toBe(1);
});
