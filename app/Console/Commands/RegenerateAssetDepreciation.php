<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Services\AssetService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RegenerateAssetDepreciation extends Command
{
    protected $signature = 'assets:regenerate-depreciation {--date= : ISO date; defaults to today}';
    protected $description = 'Computes monthly book-value snapshot for every active asset using config/assets.php category rules.';

    public function handle(AssetService $service): int
    {
        $asOf = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))
            : CarbonImmutable::today();

        $count = 0;
        Asset::query()
            ->whereNotIn('current_status', [AssetStatus::Retired->value, AssetStatus::Lost->value])
            ->whereNotNull('purchase_cost')
            ->chunkById(200, function ($chunk) use ($service, $asOf, &$count) {
                foreach ($chunk as $asset) {
                    $service->regenerateDepreciationSnapshot($asset, $asOf);
                    $count++;
                }
            });

        $this->info("Generated depreciation for {$count} assets as of {$asOf->toDateString()}");
        return self::SUCCESS;
    }
}
