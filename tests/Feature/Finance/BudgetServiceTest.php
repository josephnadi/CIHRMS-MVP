<?php

declare(strict_types=1);

use App\Enums\BudgetStatus;
use App\Models\GlAccount;
use App\Models\User;
use App\Services\Finance\BudgetService;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

it('gets or creates one draft budget per year and upserts lines', function () {
    $svc = app(BudgetService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    $budget = $svc->forYear(2026);
    expect($budget->status)->toBe(BudgetStatus::Draft)
        ->and($svc->forYear(2026)->id)->toBe($budget->id); // get-or-create, no dup

    $svc->setLine($budget, $acc, 120000);
    $svc->setLine($budget->fresh(), $acc, 150000); // upsert
    expect($budget->lines()->count())->toBe(1)
        ->and((float) $budget->lines()->first()->annual_amount)->toBe(150000.0);
});

it('approves, blocks edits while approved, and reverts to draft', function () {
    $svc = app(BudgetService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();
    $by = User::factory()->create();

    $budget = $svc->forYear(2026);
    $svc->setLine($budget, $acc, 100);
    $approved = $svc->approve($budget->fresh(), $by);

    expect($approved->status)->toBe(BudgetStatus::Approved)
        ->and($approved->approved_by)->toBe($by->id);

    // cannot edit while approved
    expect(fn () => $svc->setLine($approved, $acc, 200))->toThrow(DomainException::class);
    // cannot re-approve
    expect(fn () => $svc->approve($approved, $by))->toThrow(DomainException::class);

    $draft = $svc->revertToDraft($approved);
    expect($draft->status)->toBe(BudgetStatus::Draft)
        ->and($draft->approved_by)->toBeNull();
    $svc->setLine($draft, $acc, 200); // editable again
    expect((float) $draft->lines()->first()->annual_amount)->toBe(200.0);
});
