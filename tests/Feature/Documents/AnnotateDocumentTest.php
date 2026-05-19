<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;

it('owner can annotate a draft', function () {
    $owner = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create();
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $this->actingAs($owner)
        ->post(route('documents.annotations.store', $doc->uuid), [
            'type'  => 'stamp',
            'page'  => 1,
            'x_pct' => 10,
            'y_pct' => 20,
            'w_pct' => 18,
            'h_pct' => 6,
            'data'  => ['text' => 'APPROVED', 'color' => '#059669'],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('document_annotations', [
        'document_id' => $doc->id,
        'type'        => 'stamp',
        'page'        => 1,
    ]);
});
