<?php

declare(strict_types=1);

use App\Enums\AssetStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants auditor asset-audit view + manage', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    expect($u->hasPermission('asset_audits.view'))->toBeTrue();
    expect($u->hasPermission('asset_audits.manage'))->toBeTrue();
});

it('does not grant a plain employee asset-audit perms', function () {
    $u = User::factory()->create(['role' => 'employee']);
    expect($u->hasPermission('asset_audits.view'))->toBeFalse();
    expect($u->hasPermission('asset_audits.manage'))->toBeFalse();
});

it('ceo wildcard covers asset-audit manage', function () {
    $u = User::factory()->create(['role' => 'ceo']);
    expect($u->hasPermission('asset_audits.manage'))->toBeTrue();
});

it('AssetStatus has a label', function () {
    expect(AssetStatus::InStock->label())->toBe('In Stock');
    expect(AssetStatus::Lost->label())->toBe('Lost');
});
