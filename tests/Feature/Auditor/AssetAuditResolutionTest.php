<?php

declare(strict_types=1);

use App\Enums\AssetAuditAction;
use App\Enums\AssetAuditResult;
use App\Enums\AssetStatus;
use App\Enums\MaintenanceStatus;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetAuditService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $this->service = app(AssetAuditService::class);
    $this->actor = User::factory()->create(['role' => 'auditor']);
});

function openWithOneAsset(array $assetAttrs): array
{
    $asset = Asset::factory()->create(array_merge(['current_status' => AssetStatus::InStock->value], $assetAttrs));
    $audit = test()->service->open(['scope_type' => 'all'], test()->actor);
    return [$asset, $audit->lines()->first()];
}

it('marked_lost flips the asset to lost', function () {
    [$asset, $line] = openWithOneAsset([]);
    $this->service->count($line, AssetAuditResult::Missing, [], $this->actor);

    $this->service->applyResolution($line->fresh(), AssetAuditAction::MarkedLost, $this->actor);

    expect($asset->fresh()->current_status)->toBe(AssetStatus::Lost);
    expect($line->fresh()->resolution_action)->toBe(AssetAuditAction::MarkedLost);
});

it('relocated updates the asset location to the observed value', function () {
    [$asset, $line] = openWithOneAsset(['location' => 'HQ']);
    $this->service->count($line, AssetAuditResult::WrongLocation, ['observed_location' => 'Annex'], $this->actor);

    $this->service->applyResolution($line->fresh(), AssetAuditAction::Relocated, $this->actor);

    expect($asset->fresh()->location)->toBe('Annex');
});

it('maintenance_logged opens a maintenance record and sets status', function () {
    [$asset, $line] = openWithOneAsset([]);
    $this->service->count($line, AssetAuditResult::Damaged, [], $this->actor);

    $this->service->applyResolution($line->fresh(), AssetAuditAction::MaintenanceLogged, $this->actor);

    expect($asset->fresh()->current_status)->toBe(AssetStatus::Maintenance);
    expect($asset->maintenance()->where('status', MaintenanceStatus::Open->value)->exists())->toBeTrue();
});

it('flagged is record-only (asset unchanged)', function () {
    [$asset, $line] = openWithOneAsset(['current_status' => AssetStatus::Assigned->value]);
    $this->service->count($line, AssetAuditResult::WrongHolder, [], $this->actor);

    $this->service->applyResolution($line->fresh(), AssetAuditAction::Flagged, $this->actor);

    expect($asset->fresh()->current_status)->toBe(AssetStatus::Assigned);
    expect($line->fresh()->resolution_action)->toBe(AssetAuditAction::Flagged);
});

it('rejects an action that does not match the result', function () {
    [$asset, $line] = openWithOneAsset([]);
    $this->service->count($line, AssetAuditResult::Missing, [], $this->actor);

    $this->service->applyResolution($line->fresh(), AssetAuditAction::Relocated, $this->actor);
})->throws(DomainException::class);
