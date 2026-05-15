<?php

declare(strict_types=1);

use App\Enums\AssetStatus;
use App\Enums\AssignmentConditionOnReturn;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\User;
use App\Services\AssetService;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('registers an asset with default status in_stock', function () {
    $asset = app(AssetService::class)->register([
        'asset_tag' => 'AST-001', 'name' => 'Test Laptop', 'category' => 'laptop',
    ]);

    expect($asset->current_status)->toBe(AssetStatus::InStock);
});

it('assigns an asset and updates current_status + current_assignment_id', function () {
    $asset = Asset::create(['asset_tag' => 'AST-002', 'name' => 'Test', 'category' => 'laptop']);
    $emp = Employee::factory()->create();
    $by = User::factory()->create();

    $assignment = app(AssetService::class)->assign($asset, $emp, $by);

    expect($asset->fresh()->current_status)->toBe(AssetStatus::Assigned);
    expect($asset->fresh()->current_assignment_id)->toBe($assignment->id);
});

it('prevents double-assignment of an already-assigned asset', function () {
    $asset = Asset::create(['asset_tag' => 'AST-003', 'name' => 'Test', 'category' => 'laptop']);
    $emp1 = Employee::factory()->create();
    $emp2 = Employee::factory()->create();
    $by = User::factory()->create();
    app(AssetService::class)->assign($asset, $emp1, $by);

    expect(fn () => app(AssetService::class)->assign($asset->fresh(), $emp2, $by))
        ->toThrow(\DomainException::class, 'already assigned');
});

it('returns an asset and resets status to in_stock', function () {
    $asset = Asset::create(['asset_tag' => 'AST-004', 'name' => 'Test', 'category' => 'laptop']);
    $emp = Employee::factory()->create();
    $by = User::factory()->create();
    $assignment = app(AssetService::class)->assign($asset, $emp, $by);

    app(AssetService::class)->returnAsset($assignment, $by, AssignmentConditionOnReturn::Good);

    expect($asset->fresh()->current_status)->toBe(AssetStatus::InStock);
    expect($asset->fresh()->current_assignment_id)->toBeNull();
    expect($assignment->fresh()->returned_at)->not->toBeNull();
});

it('auto-opens a maintenance row when returned damaged', function () {
    $asset = Asset::create(['asset_tag' => 'AST-005', 'name' => 'Test', 'category' => 'laptop']);
    $emp = Employee::factory()->create();
    $by = User::factory()->create();
    $assignment = app(AssetService::class)->assign($asset, $emp, $by);

    app(AssetService::class)->returnAsset($assignment, $by, AssignmentConditionOnReturn::Damaged);

    expect($asset->maintenance()->count())->toBe(1);
    expect($asset->maintenance()->first()->status)->toBe(MaintenanceStatus::Open);
});

it('logs maintenance and sets asset status to maintenance', function () {
    $asset = Asset::create(['asset_tag' => 'AST-006', 'name' => 'Test', 'category' => 'laptop']);
    $by = User::factory()->create();

    app(AssetService::class)->logMaintenance($asset, MaintenanceType::Repair, $by);

    expect($asset->fresh()->current_status)->toBe(AssetStatus::Maintenance);
});

it('retires an asset', function () {
    $asset = Asset::create(['asset_tag' => 'AST-007', 'name' => 'Test', 'category' => 'laptop']);
    $by = User::factory()->create();

    app(AssetService::class)->retire($asset, $by, 'End of life');

    expect($asset->fresh()->current_status)->toBe(AssetStatus::Retired);
});
