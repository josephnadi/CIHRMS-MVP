<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;

it('owner routes a draft to two recipients in order', function () {
    $owner = User::factory()->create();
    $a     = User::factory()->create();
    $b     = User::factory()->create();

    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $this->actingAs($owner)
        ->post(route('documents.route', $doc->uuid), [
            'recipients' => [
                ['user_id' => $a->id, 'action_required' => 'sign'],
                ['user_id' => $b->id, 'action_required' => 'approve'],
            ],
        ])
        ->assertRedirect();

    expect($doc->fresh()->status)->toBe(DocumentStatus::InReview);
    $this->assertDatabaseCount('document_routes', 2);
    $this->assertDatabaseHas('document_routes', [
        'document_id' => $doc->id,
        'sequence'    => 1,
        'to_user_id'  => $a->id,
        'status'      => 'in_progress',
    ]);
    $this->assertDatabaseHas('document_routes', [
        'document_id' => $doc->id,
        'sequence'    => 2,
        'to_user_id'  => $b->id,
        'status'      => 'pending',
    ]);
});

it('forbids routing if not owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $r     = User::factory()->create();

    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $this->actingAs($other)
        ->post(route('documents.route', $doc->uuid), [
            'recipients' => [['user_id' => $r->id, 'action_required' => 'sign']],
        ])
        ->assertForbidden();
});
