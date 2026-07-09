<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\ExternalCollection;
use App\Models\FeeGlMapping;
use App\Models\JournalEntry;
use App\Models\RevenueRecognitionEntry;
use App\Models\RevenueRecognitionSchedule;
use App\Models\User;
use App\Services\Finance\RevenueRecognitionService;
use App\Services\Website\CollectionIngestionService;

beforeEach(function () {
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\FeeGlMappingSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // posting actor
});

/** Build a deferred subscription collection row (paid 2026-07-05). */
function subscriptionCollection(float $amount = 350.00): ExternalCollection
{
    return ExternalCollection::create([
        'source' => 'member_fee_payment', 'source_id' => 1, 'external_ref' => 'TXN-SUB-1',
        'fee_code' => 'member.subscription', 'amount' => $amount, 'currency' => 'GHS',
        'paid_at' => '2026-07-05 10:00:00', 'status' => ExternalCollection::STATUS_POSTED,
    ]);
}

it('builds a 12-month schedule starting on the payment month, entries summing to the total', function () {
    $collection = subscriptionCollection(350.00);
    $mapping = FeeGlMapping::forCode('member.subscription');

    $schedule = app(RevenueRecognitionService::class)->scheduleForCollection($collection, $mapping);

    expect($schedule->months)->toBe(12)
        ->and($schedule->start_date->toDateString())->toBe('2026-07-05')
        ->and($schedule->deferredAccount->code)->toBe('2400')
        ->and($schedule->incomeAccount->code)->toBe('4110')
        ->and($schedule->entries)->toHaveCount(12);

    $entries = $schedule->entries->sortBy('period_month')->values();
    expect($entries->first()->period_month)->toBe('2026-07')
        ->and($entries->last()->period_month)->toBe('2027-06')
        ->and(round($schedule->entries->sum('amount'), 2))->toBe(350.00);
});

it('is idempotent — one schedule per collection', function () {
    $collection = subscriptionCollection();
    $mapping = FeeGlMapping::forCode('member.subscription');
    $svc = app(RevenueRecognitionService::class);

    $a = $svc->scheduleForCollection($collection, $mapping);
    $b = $svc->scheduleForCollection($collection, $mapping);

    expect($b->id)->toBe($a->id)
        ->and(RevenueRecognitionSchedule::count())->toBe(1)
        ->and(RevenueRecognitionEntry::count())->toBe(12);
});

it('creates the schedule automatically when a deferred subscription is ingested', function () {
    app(CollectionIngestionService::class)->ingest([
        'source' => 'member_fee_payment', 'source_id' => 9, 'external_ref' => 'TXN-SUB-ING',
        'external_user_id' => null, 'fee_code' => 'member.subscription', 'amount' => 600, 'currency' => 'GHS',
        'paid_at' => '2026-07-05T10:00:00Z', 'method' => 'momo', 'gateway_ref' => 'h1', 'meta' => [],
    ]);

    $schedule = RevenueRecognitionSchedule::where('source_type', 'external_collection')->first();
    expect($schedule)->not->toBeNull()
        ->and((float) $schedule->total_amount)->toBe(600.00)
        ->and($schedule->entries()->count())->toBe(12);
});

it('recognises only the due month: DR 2400 / CR income, and leaves future months pending', function () {
    $schedule = app(RevenueRecognitionService::class)
        ->scheduleForCollection(subscriptionCollection(350.00), FeeGlMapping::forCode('member.subscription'));

    $report = app(RevenueRecognitionService::class)->recognizeForMonth('2026-07');

    expect($report['recognized'])->toBe(1)
        ->and(RevenueRecognitionEntry::where('status', 'recognized')->count())->toBe(1)
        ->and(RevenueRecognitionEntry::where('status', 'pending')->count())->toBe(11);

    $entry = RevenueRecognitionEntry::where('status', 'recognized')->first();
    $je = JournalEntry::with('lines.glAccount')->find($entry->journal_entry_id);
    expect($je->source_type)->toBe(JournalSourceType::RevenueRecognition)
        ->and(round((float) $je->lines->sum('debit_amount'), 2))->toBe(round((float) $je->lines->sum('credit_amount'), 2))
        ->and($je->lines->firstWhere('debit_amount', '>', 0)->glAccount->code)->toBe('2400')
        ->and($je->lines->firstWhere('credit_amount', '>', 0)->glAccount->code)->toBe('4110');
});

it('is idempotent and completes the schedule once fully released', function () {
    $svc = app(RevenueRecognitionService::class);
    $schedule = $svc->scheduleForCollection(subscriptionCollection(350.00), FeeGlMapping::forCode('member.subscription'));

    $svc->recognizeForMonth('2026-07');
    $again = $svc->recognizeForMonth('2026-07'); // re-run same month
    expect($again['recognized'])->toBe(0)
        ->and(JournalEntry::where('source_type', JournalSourceType::RevenueRecognition->value)->count())->toBe(1);

    // Recognise through the final month.
    $final = $svc->recognizeForMonth('2027-06');
    expect($final['recognized'])->toBe(11)
        ->and($schedule->fresh()->status)->toBe(RevenueRecognitionSchedule::STATUS_COMPLETED)
        ->and(round((float) $schedule->fresh()->recognized_total, 2))->toBe(350.00)
        ->and(RevenueRecognitionEntry::where('status', 'pending')->count())->toBe(0);
});
