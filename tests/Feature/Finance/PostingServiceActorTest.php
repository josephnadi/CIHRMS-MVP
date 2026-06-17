<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
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
});

function actorDoc(): PostingDocument
{
    return new PostingDocument(
        sourceType: JournalSourceType::Payroll,
        sourceId: 501,
        purpose: 'accrual',
        date: '2026-06-17',
        narration: 'actor doc',
        lines: [
            PostingLine::debit(slug: 'payroll.salary_expense', amount: 100.0),
            PostingLine::credit(slug: 'payroll.net_pay_payable', amount: 100.0),
        ],
    );
}

it('stamps created_by and posted_by from an explicit actor with no auth', function () {
    $actor = User::factory()->create();

    $entry = app(PostingService::class)->post(actorDoc(), $actor);

    expect($entry->created_by)->toBe($actor->id)
        ->and($entry->posted_by)->toBe($actor->id);
});

it('falls back to the system super_admin instead of null when no auth and no actor', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $entry = app(PostingService::class)->post(actorDoc());

    expect($entry->created_by)->toBe($admin->id)
        ->and($entry->posted_by)->toBe($admin->id);
});
