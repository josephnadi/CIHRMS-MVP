<?php

use App\Enums\DocumentRouteAction;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentRoutingService;

it('owner withdraws an in-review document', function () {
    $owner = User::factory()->create();
    $a     = User::factory()->create();

    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    app(DocumentRoutingService::class)->route($doc, [
        ['user_id' => $a->id, 'action_required' => DocumentRouteAction::Sign],
    ]);

    $this->actingAs($owner)
        ->post(route('documents.withdraw', $doc->uuid))
        ->assertRedirect();

    expect($doc->fresh()->status)->toBe(DocumentStatus::Withdrawn);
});
