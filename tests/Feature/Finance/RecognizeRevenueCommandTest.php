<?php

declare(strict_types=1);

use App\Models\ExternalCollection;
use App\Models\FeeGlMapping;
use App\Models\RevenueRecognitionEntry;
use App\Models\User;
use App\Services\Finance\RevenueRecognitionService;

beforeEach(function () {
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\FeeGlMappingSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    User::factory()->create(['role' => 'super_admin']);

    $collection = ExternalCollection::create([
        'source' => 'member_fee_payment', 'source_id' => 1, 'external_ref' => 'TXN-CMD-1',
        'fee_code' => 'member.subscription', 'amount' => 360, 'currency' => 'GHS',
        'paid_at' => '2026-07-05 10:00:00', 'status' => ExternalCollection::STATUS_POSTED,
    ]);
    app(RevenueRecognitionService::class)->scheduleForCollection($collection, FeeGlMapping::forCode('member.subscription'));
});

it('recognises the given month and reports the count', function () {
    $this->artisan('finance:recognize-revenue', ['month' => '2026-07'])
        ->assertExitCode(0)
        ->expectsOutputToContain('recognized: 1');

    expect(RevenueRecognitionEntry::where('status', 'recognized')->count())->toBe(1);
});

it('rejects a malformed month argument', function () {
    $this->artisan('finance:recognize-revenue', ['month' => 'July'])
        ->assertExitCode(1);
});
