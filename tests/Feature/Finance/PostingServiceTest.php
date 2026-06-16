<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Finance\PostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    $this->actingAs(User::factory()->create());
});

function payrollAccrualDoc(string $purpose = 'accrual'): PostingDocument
{
    return new PostingDocument(
        sourceType: JournalSourceType::Payroll,
        sourceId: 7,
        purpose: $purpose,
        date: '2026-06-16',
        narration: 'Payroll accrual run 7',
        lines: [
            PostingLine::debit(slug: 'payroll.salary_expense', amount: 1000.0, narration: 'gross'),
            PostingLine::credit(slug: 'payroll.paye_payable', amount: 150.0),
            PostingLine::credit(slug: 'payroll.net_pay_payable', amount: 850.0),
        ],
    );
}

it('posts a balanced journal entry and updates balances', function () {
    $entry = app(PostingService::class)->post(payrollAccrualDoc());

    expect($entry->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->source_type)->toBe(JournalSourceType::Payroll)
        ->and($entry->source_id)->toBe(7)
        ->and($entry->source_purpose)->toBe('accrual')
        ->and($entry->lines)->toHaveCount(3)
        ->and(str_starts_with($entry->reference, 'JE-'))->toBeTrue();

    $expense = GlAccount::where('code', '5100')->firstOrFail();
    $netpay  = GlAccount::where('code', '2300')->firstOrFail();
    expect((float) GlAccountBalance::where('gl_account_id', $expense->id)->value('balance'))->toBe(1000.0)
        ->and((float) GlAccountBalance::where('gl_account_id', $netpay->id)->value('balance'))->toBe(850.0);
});

it('is idempotent: re-posting the same source returns the existing entry without double-counting', function () {
    $first  = app(PostingService::class)->post(payrollAccrualDoc());
    $second = app(PostingService::class)->post(payrollAccrualDoc());

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::where('source_type', JournalSourceType::Payroll->value)->where('source_id', 7)->count())->toBe(1);

    $expense = GlAccount::where('code', '5100')->firstOrFail();
    expect((float) GlAccountBalance::where('gl_account_id', $expense->id)->value('balance'))->toBe(1000.0);
});

it('resolves literal account ids as well as slugs', function () {
    $bankGl = GlAccount::where('code', '1100')->firstOrFail();
    $doc = new PostingDocument(
        sourceType: JournalSourceType::Disbursement,
        sourceId: 3,
        purpose: 'settlement',
        date: '2026-06-16',
        narration: 'Settle net pay',
        lines: [
            PostingLine::debit(slug: 'payroll.net_pay_payable', amount: 850.0),
            PostingLine::credit(accountId: $bankGl->id, amount: 850.0, narration: 'cash out'),
        ],
    );

    $entry = app(PostingService::class)->post($doc);
    $bankLine = $entry->lines->firstWhere('gl_account_id', $bankGl->id);
    expect($bankLine)->not->toBeNull()->and((float) $bankLine->credit_amount)->toBe(850.0);
});

it('reverses a posted entry for a source', function () {
    $entry = app(PostingService::class)->post(payrollAccrualDoc());
    $by = User::factory()->create();

    $reversal = app(PostingService::class)->reverseFor(
        JournalSourceType::Payroll, 7, 'accrual', $by, 'run cancelled'
    );

    expect($reversal->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->fresh()->status)->toBe(JournalEntryStatus::Reversed);

    $expense = GlAccount::where('code', '5100')->firstOrFail();
    expect((float) GlAccountBalance::where('gl_account_id', $expense->id)->value('balance'))->toBe(0.0);
});

it('keeps distinct purposes for the same source as separate entries', function () {
    $accrual = app(PostingService::class)->post(payrollAccrualDoc('accrual'));

    $settlement = new PostingDocument(
        sourceType: JournalSourceType::Payroll,
        sourceId: 7,
        purpose: 'settlement',
        date: '2026-06-16',
        narration: 'Settle run 7',
        lines: [
            PostingLine::debit(slug: 'payroll.net_pay_payable', amount: 850.0),
            PostingLine::credit(slug: 'bank.cash_in_transit', amount: 850.0),
        ],
    );
    $settled = app(PostingService::class)->post($settlement);

    expect($settled->id)->not->toBe($accrual->id)
        ->and(JournalEntry::where('source_type', JournalSourceType::Payroll->value)->where('source_id', 7)->count())->toBe(2);
});

it('does not deduplicate ad-hoc entries with a null source id', function () {
    $make = fn () => new PostingDocument(
        sourceType: JournalSourceType::Manual,
        sourceId: null,
        purpose: '',
        date: '2026-06-16',
        narration: 'ad-hoc',
        lines: [
            PostingLine::debit(slug: 'payroll.salary_expense', amount: 10.0),
            PostingLine::credit(slug: 'payroll.net_pay_payable', amount: 10.0),
        ],
    );

    $a = app(PostingService::class)->post($make());
    $b = app(PostingService::class)->post($make());

    expect($b->id)->not->toBe($a->id);
});

it('rejects an unbalanced document and writes no rows', function () {
    $before = JournalEntry::count();

    $doc = new PostingDocument(
        sourceType: JournalSourceType::Payroll,
        sourceId: 9,
        purpose: 'accrual',
        date: '2026-06-16',
        narration: 'unbalanced',
        lines: [
            PostingLine::debit(slug: 'payroll.salary_expense', amount: 100.0),
            PostingLine::credit(slug: 'payroll.net_pay_payable', amount: 90.0),
        ],
    );

    expect(fn () => app(PostingService::class)->post($doc))->toThrow(DomainException::class);
    expect(JournalEntry::count())->toBe($before);
});
