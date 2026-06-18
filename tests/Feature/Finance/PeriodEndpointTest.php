<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Finance\FiscalCalendarService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    $this->period = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 1)->firstOrFail();
});

// Mark a fresh 2FA challenge for the acting user so the `2fa:fresh` middleware
// on the close/reopen/lock POST routes lets the request through (mirrors
// ApPayment2faTest::apPay2fa).
function periodEndpoint2fa(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);

    return $user;
}

it('finance_officer can view the fiscal calendar page', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/periods')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/FiscalCalendar/Index'));
});

it('employee is forbidden from the fiscal calendar', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/periods')->assertForbidden();
});

it('finance_officer can close and reopen a period', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs(periodEndpoint2fa($u))->post("/finance/periods/{$this->period->id}/close")->assertRedirect();
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Closed);

    $this->actingAs(periodEndpoint2fa($u))->post("/finance/periods/{$this->period->id}/reopen")->assertRedirect();
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Open);
});

it('finance_officer cannot lock (privileged), super_admin can', function () {
    $fo = User::factory()->create(['role' => 'finance_officer']);
    $sa = User::factory()->create(['role' => 'super_admin']);

    // close first (lock requires closed)
    $this->actingAs(periodEndpoint2fa($fo))->post("/finance/periods/{$this->period->id}/close")->assertRedirect();

    $this->actingAs(periodEndpoint2fa($fo))->post("/finance/periods/{$this->period->id}/lock")->assertForbidden();
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Closed);

    $this->actingAs(periodEndpoint2fa($sa))->post("/finance/periods/{$this->period->id}/lock")->assertRedirect();
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Locked);
});

it('rejects reopening a locked period with a validation error', function () {
    $sa = User::factory()->create(['role' => 'super_admin']);
    $this->actingAs(periodEndpoint2fa($sa))->post("/finance/periods/{$this->period->id}/close")->assertRedirect();
    $this->actingAs(periodEndpoint2fa($sa))->post("/finance/periods/{$this->period->id}/lock")->assertRedirect();

    $this->actingAs(periodEndpoint2fa($sa))->post("/finance/periods/{$this->period->id}/reopen")->assertSessionHasErrors();
    expect($this->period->fresh()->status)->toBe(FiscalPeriodStatus::Locked);
});

it('includes the subledger reconciliation rows on the calendar page', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/periods')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/FiscalCalendar/Index')->has('reconciliation', 3));
});
