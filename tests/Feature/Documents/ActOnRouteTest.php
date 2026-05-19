<?php

use App\Enums\DocumentRouteAction;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentRoutingService;

it('recipient can complete their route hop', function () {
    $owner = User::factory()->create();
    $a     = User::factory()->create();

    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    app(DocumentRoutingService::class)->route($doc, [
        ['user_id' => $a->id, 'action_required' => DocumentRouteAction::Sign],
    ]);
    $route = $doc->routes()->first();

    $this->actingAs($a)
        ->post(route('documents.routes.act', ['document' => $doc->uuid, 'route' => $route->id]), [
            'decision' => 'complete',
        ])
        ->assertRedirect();

    expect($doc->fresh()->status->value)->toBe('completed');
});

it('non-recipient cannot act', function () {
    $owner    = User::factory()->create();
    $a        = User::factory()->create();
    $imposter = User::factory()->create();

    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    app(DocumentRoutingService::class)->route($doc, [
        ['user_id' => $a->id, 'action_required' => DocumentRouteAction::Sign],
    ]);
    $route = $doc->routes()->first();

    $this->actingAs($imposter)
        ->post(route('documents.routes.act', ['document' => $doc->uuid, 'route' => $route->id]), [
            'decision' => 'complete',
        ])
        ->assertForbidden();
});
