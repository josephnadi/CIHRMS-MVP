<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Services\AssetService;
use Carbon\CarbonImmutable;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('computes straight-line depreciation for a laptop after 18 months', function () {
    $asset = Asset::create([
        'asset_tag' => 'DEP-001', 'name' => 'Test Laptop', 'category' => 'laptop',
        'purchase_date' => '2024-11-15', 'purchase_cost' => 3000.00,
    ]);

    $snapshot = app(AssetService::class)->regenerateDepreciationSnapshot(
        $asset, CarbonImmutable::parse('2026-05-15')
    );

    // 3000 - 5% salvage = 2850 depreciable over 36 months
    // 18 months elapsed → 18/36 = 50% → 1425 depreciated → book = 1575
    expect((float) $snapshot->book_value)->toBe(1575.00);
});

it('returns salvage value when asset is fully depreciated', function () {
    $asset = Asset::create([
        'asset_tag' => 'DEP-002', 'name' => 'Old Laptop', 'category' => 'laptop',
        'purchase_date' => '2020-01-01', 'purchase_cost' => 3000.00,
    ]);

    $snapshot = app(AssetService::class)->regenerateDepreciationSnapshot(
        $asset, CarbonImmutable::parse('2026-05-15')
    );

    expect((float) $snapshot->book_value)->toBe(150.00); // 5% of 3000
});
