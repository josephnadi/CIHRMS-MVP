<?php

declare(strict_types=1);

use App\Enums\AssetAuditStatus;
use App\Enums\AssetStatus;
use App\Events\AssetAuditOpened;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetAuditService;
use Illuminate\Support\Facades\Event;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $this->service = app(AssetAuditService::class);
});

it('open() snapshots the expected assets and excludes retired/lost', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value, 'location' => 'HQ']);
    Asset::factory()->create(['current_status' => AssetStatus::Assigned->value]);
    Asset::factory()->create(['current_status' => AssetStatus::Retired->value]);
    Asset::factory()->create(['current_status' => AssetStatus::Lost->value]);

    $actor = User::factory()->create(['role' => 'auditor']);
    $audit = $this->service->open(['scope_type' => 'all'], $actor);

    expect($audit->status)->toBe(AssetAuditStatus::InProgress);
    expect($audit->reference)->toStartWith('ASA-');
    expect($audit->total_lines)->toBe(2);            // retired + lost excluded
    expect($audit->lines()->count())->toBe(2);
    expect($audit->events()->where('action', 'opened')->exists())->toBeTrue();
});

it('open() with category scope only snapshots that category', function () {
    Asset::factory()->create(['category' => 'laptop', 'current_status' => AssetStatus::InStock->value]);
    Asset::factory()->create(['category' => 'monitor', 'current_status' => AssetStatus::InStock->value]);

    $actor = User::factory()->create(['role' => 'auditor']);
    $audit = $this->service->open(['scope_type' => 'category', 'scope_value' => 'laptop'], $actor);

    expect($audit->total_lines)->toBe(1);
});

it('open() dispatches AssetAuditOpened', function () {
    Event::fake([AssetAuditOpened::class]);
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $actor = User::factory()->create(['role' => 'auditor']);
    $this->service->open(['scope_type' => 'all'], $actor);
    Event::assertDispatched(AssetAuditOpened::class);
});
