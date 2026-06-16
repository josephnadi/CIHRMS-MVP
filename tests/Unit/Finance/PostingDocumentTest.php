<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;

it('builds debit and credit lines via factories', function () {
    $dr = PostingLine::debit(slug: 'payroll.salary_expense', amount: 100.0, narration: 'gross');
    $cr = PostingLine::credit(accountId: 5, amount: 100.0);

    expect($dr->accountSlug)->toBe('payroll.salary_expense')
        ->and($dr->debit)->toBe(100.0)->and($dr->credit)->toBe(0.0)
        ->and($cr->accountId)->toBe(5)->and($cr->credit)->toBe(100.0);
});

it('rejects a line with both slug and id', function () {
    expect(fn () => new PostingLine(accountId: 5, accountSlug: 'x', debit: 1, credit: 0))
        ->toThrow(DomainException::class);
});

it('rejects a line with neither debit nor credit', function () {
    expect(fn () => new PostingLine(accountId: 5, accountSlug: null, debit: 0, credit: 0))
        ->toThrow(DomainException::class);
});

it('rejects a line with both debit and credit', function () {
    expect(fn () => new PostingLine(accountId: 5, accountSlug: null, debit: 1, credit: 1))
        ->toThrow(DomainException::class);
});

it('requires at least two lines and reports balance', function () {
    $lines = [
        PostingLine::debit(slug: 'payroll.salary_expense', amount: 100.0),
        PostingLine::credit(slug: 'payroll.net_pay_payable', amount: 100.0),
    ];
    $doc = new PostingDocument(
        sourceType: JournalSourceType::Payroll,
        sourceId: 1,
        purpose: 'accrual',
        date: '2026-06-16',
        narration: 'Payroll accrual',
        lines: $lines,
    );

    expect($doc->isBalanced())->toBeTrue()->and($doc->purpose)->toBe('accrual');

    expect(fn () => new PostingDocument(
        sourceType: JournalSourceType::Payroll, sourceId: 1, purpose: '',
        date: '2026-06-16', narration: 'x',
        lines: [PostingLine::debit(slug: 'a', amount: 1)],
    ))->toThrow(DomainException::class);
});
