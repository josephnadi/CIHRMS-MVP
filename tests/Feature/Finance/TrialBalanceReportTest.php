<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\Reports\TrialBalanceReport;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

function postEntry(array $lines, string $date = '2026-06-15'): void
{
    // $lines: array of [code, debit, credit]
    $je = JournalEntry::create([
        'reference' => 'JE-TB-' . uniqid(), 'entry_date' => $date, 'narration' => 'tb',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    $no = 1;
    foreach ($lines as [$code, $debit, $credit]) {
        JournalLine::create([
            'journal_entry_id' => $je->id, 'line_no' => $no++,
            'gl_account_id' => GlAccount::where('code', $code)->value('id'),
            'debit_amount' => $debit, 'credit_amount' => $credit,
        ]);
    }
}

it('produces a balanced trial balance (debits = credits)', function () {
    // Two balanced entries.
    postEntry([['5100', 1000, 0], ['2300', 0, 1000]]); // expense / liability
    postEntry([['1100', 2000, 0], ['4100', 0, 2000]]); // asset / income

    $report = app(TrialBalanceReport::class)->forDate(CarbonImmutable::create(2026, 6, 30));

    expect($report['balanced'])->toBeTrue()
        ->and($report['total_debit'])->toBe($report['total_credit'])
        ->and($report['total_debit'])->toBe(3000.0); // 1000 expense + 2000 asset on the debit side

    $byCode = collect($report['rows'])->keyBy('code');
    expect($byCode['5100']['debit'])->toBe(1000.0)->and($byCode['5100']['credit'])->toBe(0.0)
        ->and($byCode['2300']['credit'])->toBe(1000.0)->and($byCode['2300']['debit'])->toBe(0.0)
        ->and($byCode['4100']['credit'])->toBe(2000.0);
});

it('returns an empty, balanced report when there are no postings', function () {
    $report = app(TrialBalanceReport::class)->forDate(CarbonImmutable::create(2026, 6, 30));
    expect($report['rows'])->toBe([])
        ->and($report['total_debit'])->toBe(0.0)
        ->and($report['total_credit'])->toBe(0.0)
        ->and($report['balanced'])->toBeTrue();
});
