<?php

use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentRoute;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Policies\DocumentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('owner can move their own annotation on a draft', function () {
    $owner = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create(['status' => DocumentStatus::Draft]);
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);
    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'type' => 'signature',
    ]);
    expect((new DocumentPolicy())->moveAnnotation($owner, $ann))->toBeTrue();
});

it('third party cannot move annotation', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create(['status' => DocumentStatus::Draft]);
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);
    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'type' => 'signature',
    ]);
    expect((new DocumentPolicy())->moveAnnotation($other, $ann))->toBeFalse();
});

it('doc owner can move another user\'s annotation on a draft', function () {
    $owner   = User::factory()->create();
    $creator = User::factory()->create();
    $doc     = Document::factory()->for($owner, 'owner')->create(['status' => DocumentStatus::Draft]);
    $v       = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);
    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $creator->id, 'type' => 'signature',
    ]);
    expect((new DocumentPolicy())->moveAnnotation($owner, $ann))->toBeTrue();
});

it('locks annotation on completed route', function () {
    $owner = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create(['status' => DocumentStatus::InReview]);
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);
    $r = DocumentRoute::factory()->for($doc)->for($v, 'version')->create([
        'from_user_id' => $owner->id, 'to_user_id' => $owner->id,
        'status' => DocumentRouteStatus::Completed,
    ]);
    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'route_id' => $r->id,
    ]);
    expect((new DocumentPolicy())->moveAnnotation($owner, $ann))->toBeFalse();
});
