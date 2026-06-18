<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Exceptions\Finance\SubledgerVarianceException;
use App\Models\FiscalPeriod;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\User;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\PeriodCloseService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    $this->period = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 1)->firstOrFail();
    $this->user = User::factory()->create();
});

it('closes normally when the books are in balance', function () {
    app(PeriodCloseService::class)->close($this->period, $this->user);
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Closed);
});

it('blocks close on a subledger variance unless acknowledged', function () {
    $id = GlAccount::where('code', '2100')->value('id');
    GlAccountBalance::where('gl_account_id', $id)->update(['balance' => 500.0]); // AP GL diverges

    expect(fn () => app(PeriodCloseService::class)->close($this->period, $this->user))
        ->toThrow(SubledgerVarianceException::class);

    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Open); // not closed
});

it('closes despite a variance when acknowledged (audited override)', function () {
    $id = GlAccount::where('code', '2100')->value('id');
    GlAccountBalance::where('gl_account_id', $id)->update(['balance' => 500.0]);

    app(PeriodCloseService::class)->close($this->period, $this->user, acknowledgeVariance: true);

    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Closed);
});
