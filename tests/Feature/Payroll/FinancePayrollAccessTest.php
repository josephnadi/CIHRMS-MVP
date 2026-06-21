<?php

declare(strict_types=1);

use App\Models\PayrollRun;
use App\Models\User;

// Finance officers run payroll, so they must be able to create (initiate) a run,
// not just view/approve it. The store route is gated by `payroll.run`.

it('lets a finance_officer create a payroll run', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    expect($finance->hasPermission('payroll.run'))->toBeTrue();

    $this->actingAs($finance)
        ->post(route('payroll-runs.store'), [
            'period_year'  => 2026,
            'period_month' => 6,
        ])
        ->assertRedirect();

    expect(PayrollRun::where('period_year', 2026)->where('period_month', 6)->exists())->toBeTrue();
});

it('still forbids a plain employee from creating a payroll run', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)
        ->post(route('payroll-runs.store'), ['period_year' => 2026, 'period_month' => 6])
        ->assertForbidden();
});
