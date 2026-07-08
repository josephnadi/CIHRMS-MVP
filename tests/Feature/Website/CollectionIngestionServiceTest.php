<?php
declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\ExternalCollection;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Website\CollectionIngestionService;

beforeEach(function () {
    // CihrmChartOfAccountsSeeder reuses the structural parents/accounts (e.g. 5100)
    // that ChartOfAccountsSeeder creates, so it must run first — see its docblock.
    (new \Database\Seeders\ChartOfAccountsSeeder())->run();
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\FeeGlMappingSeeder())->run();
    // Runs last: FeeGlMappingSeeder creates new GL accounts (1131, 4700-4760),
    // and every account needs a balance row before JournalPostingService can post to it.
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // posting actor fallback: ingestion runs
    // unauthenticated (webhook context), so PostingActorResolver needs a super_admin to stamp
    // created_by on the JournalEntry it creates.
});

function wsRecord(array $over = []): array {
    return array_merge([
        'source' => 'payment_record', 'source_id' => 7, 'external_ref' => 'PR-7',
        'external_user_id' => null, 'payer_name' => 'X', 'payer_email' => null, 'payer_phone' => null,
        'fee_code' => 'exam', 'amount' => 200.00, 'currency' => 'GHS',
        'paid_at' => '2026-07-05T10:00:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => [],
    ], $over);
}

it('posts a non-deferred collection DR clearing / CR income', function () {
    $c = app(CollectionIngestionService::class)->ingest(wsRecord());

    expect($c->status)->toBe(ExternalCollection::STATUS_POSTED)
        ->and($c->journal_entry_id)->not->toBeNull();

    $entry = JournalEntry::with('lines')->find($c->journal_entry_id);
    $dr = round((float) $entry->lines->sum('debit_amount'), 2);
    $cr = round((float) $entry->lines->sum('credit_amount'), 2);
    expect($dr)->toBe(200.00)->and($cr)->toBe(200.00)
        ->and($entry->source_type)->toBe(JournalSourceType::WebsiteCollection);
});

it('credits deferred income 2400 for a subscription', function () {
    $c = app(CollectionIngestionService::class)->ingest(wsRecord([
        'source' => 'member_fee_payment', 'external_ref' => 'TXN-9', 'fee_code' => 'member.subscription', 'amount' => 350,
    ]));
    $entry = JournalEntry::with('lines.glAccount')->find($c->journal_entry_id);
    $creditedCodes = $entry->lines->where('credit_amount', '>', 0)->pluck('glAccount.code');
    expect($creditedCodes)->toContain('2400');
});

it('is idempotent on (source, external_ref)', function () {
    $svc = app(CollectionIngestionService::class);
    $a = $svc->ingest(wsRecord());
    $b = $svc->ingest(wsRecord());
    expect($b->id)->toBe($a->id)
        ->and(ExternalCollection::where('external_ref', 'PR-7')->count())->toBe(1)
        ->and(JournalEntry::where('source_type', JournalSourceType::WebsiteCollection->value)->count())->toBe(1);
});

it('parks an unmapped fee code without posting', function () {
    $c = app(CollectionIngestionService::class)->ingest(wsRecord(['fee_code' => 'mystery.fee', 'external_ref' => 'PR-8']));
    expect($c->status)->toBe(ExternalCollection::STATUS_UNMAPPED)
        ->and($c->journal_entry_id)->toBeNull();
});

it('parks a non-GHS collection as error', function () {
    $c = app(CollectionIngestionService::class)->ingest(wsRecord(['currency' => 'USD', 'external_ref' => 'PR-9']));
    expect($c->status)->toBe(ExternalCollection::STATUS_ERROR);
});
