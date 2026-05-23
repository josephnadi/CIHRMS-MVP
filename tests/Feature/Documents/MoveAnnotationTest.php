<?php

use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentVersion;
use App\Models\User;

it('owner can move their own annotation', function () {
    $owner = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create();
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'type' => 'signature',
        'x_pct' => 10, 'y_pct' => 10, 'w_pct' => 22, 'h_pct' => 8,
    ]);

    $this->actingAs($owner)
        ->patch(route('documents.annotations.update', ['document' => $doc->uuid, 'annotation' => $ann->id]), [
            'x_pct' => 30, 'y_pct' => 40, 'w_pct' => 25, 'h_pct' => 10, 'rotation' => 15,
        ])
        ->assertRedirect();

    $fresh = $ann->fresh();
    expect((float) $fresh->x_pct)->toBe(30.0)
        ->and((float) $fresh->y_pct)->toBe(40.0)
        ->and($fresh->rotation)->toBe(15);

    $this->assertDatabaseHas('document_events', ['type' => 'annotation_moved']);
    $this->assertDatabaseHas('document_events', ['type' => 'annotation_resized']);
});

it('non-owner cannot move annotation', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create();
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'type' => 'signature',
    ]);

    $this->actingAs($other)
        ->patch(route('documents.annotations.update', ['document' => $doc->uuid, 'annotation' => $ann->id]), [
            'x_pct' => 50,
        ])
        ->assertForbidden();
});

it('rejects out-of-range coordinates', function () {
    $owner = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create();
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'type' => 'signature',
    ]);

    $this->actingAs($owner)
        ->patch(route('documents.annotations.update', ['document' => $doc->uuid, 'annotation' => $ann->id]), [
            'x_pct' => 150,
        ])
        ->assertSessionHasErrors('x_pct');
});
