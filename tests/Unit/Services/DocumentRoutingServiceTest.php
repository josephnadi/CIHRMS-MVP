<?php

use App\Enums\DocumentRouteAction;
use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentRoutingService;

uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(DocumentRoutingService::class);
    $this->owner = User::factory()->create();
    $this->alice = User::factory()->create();
    $this->bob   = User::factory()->create();

    $this->doc = Document::factory()->for($this->owner, 'owner')->create([
        'status' => DocumentStatus::Draft,
    ]);
    $this->version = DocumentVersion::factory()->for($this->doc)->create();
    $this->doc->update(['current_version_id' => $this->version->id]);
});

it('creates ordered routes and marks first in_progress', function () {
    $this->service->route($this->doc, [
        ['user_id' => $this->alice->id, 'action_required' => DocumentRouteAction::Sign],
        ['user_id' => $this->bob->id,   'action_required' => DocumentRouteAction::Approve],
    ]);

    expect($this->doc->fresh()->status)->toBe(DocumentStatus::InReview);

    $routes = $this->doc->routes()->orderBy('sequence')->get();
    expect($routes)->toHaveCount(2);
    expect($routes[0]->to_user_id)->toBe($this->alice->id);
    expect($routes[0]->status)->toBe(DocumentRouteStatus::InProgress);
    expect($routes[1]->status)->toBe(DocumentRouteStatus::Pending);
});

it('advances to next hop on complete action', function () {
    $this->service->route($this->doc, [
        ['user_id' => $this->alice->id, 'action_required' => DocumentRouteAction::Sign],
        ['user_id' => $this->bob->id,   'action_required' => DocumentRouteAction::Approve],
    ]);
    $route1 = $this->doc->routes()->orderBy('sequence')->first();

    $this->service->act($route1, 'complete', null, $this->alice);

    $routes = $this->doc->routes()->orderBy('sequence')->get();
    expect($routes[0]->status)->toBe(DocumentRouteStatus::Completed);
    expect($routes[1]->status)->toBe(DocumentRouteStatus::InProgress);
    expect($this->doc->fresh()->status)->toBe(DocumentStatus::InReview);
});

it('completes the document when last hop is acted', function () {
    $this->service->route($this->doc, [
        ['user_id' => $this->alice->id, 'action_required' => DocumentRouteAction::Sign],
    ]);
    $route = $this->doc->routes()->first();

    $this->service->act($route, 'complete', null, $this->alice);

    expect($this->doc->fresh()->status)->toBe(DocumentStatus::Completed);
    expect($route->fresh()->status)->toBe(DocumentRouteStatus::Completed);
});

it('rejects the document and marks subsequent routes cancelled', function () {
    $this->service->route($this->doc, [
        ['user_id' => $this->alice->id, 'action_required' => DocumentRouteAction::Sign],
        ['user_id' => $this->bob->id,   'action_required' => DocumentRouteAction::Approve],
    ]);
    $route1 = $this->doc->routes()->orderBy('sequence')->first();

    $this->service->act($route1, 'reject', 'Wrong document', $this->alice);

    expect($this->doc->fresh()->status)->toBe(DocumentStatus::Rejected);
    $routes = $this->doc->routes()->orderBy('sequence')->get();
    expect($routes[0]->status)->toBe(DocumentRouteStatus::Rejected);
    expect($routes[1]->status)->toBe(DocumentRouteStatus::Cancelled);
});

it('withdraws an in-review document and cancels in-progress route', function () {
    $this->service->route($this->doc, [
        ['user_id' => $this->alice->id, 'action_required' => DocumentRouteAction::Sign],
    ]);

    $this->service->withdraw($this->doc, $this->owner);

    expect($this->doc->fresh()->status)->toBe(DocumentStatus::Withdrawn);
    expect($this->doc->routes()->first()->fresh()->status)->toBe(DocumentRouteStatus::Cancelled);
});
