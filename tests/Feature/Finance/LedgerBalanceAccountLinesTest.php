<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\LedgerBalanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

it('lists the posted lines for an account within a window', function () {
    $exp = GlAccount::where('code', '5100')->firstOrFail();
    $pay = GlAccount::where('code', '2300')->firstOrFail();

    $je = JournalEntry::create([
        'reference' => 'JE-AL-1', 'entry_date' => '2026-06-15', 'narration' => 'salaries',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $exp->id, 'debit_amount' => 1000, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $pay->id, 'debit_amount' => 0, 'credit_amount' => 1000]);

    $lines = app(LedgerBalanceService::class)->accountLines($exp->id, null, CarbonImmutable::create(2026, 6, 30));

    expect($lines)->toHaveCount(1);
    expect($lines->first()->reference)->toBe('JE-AL-1')
        ->and((float) $lines->first()->debit)->toBe(1000.0)
        ->and($lines->first()->narration)->toBe('salaries');
});
