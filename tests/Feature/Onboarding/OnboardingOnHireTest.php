<?php

declare(strict_types=1);

use App\Events\EmployeeCreated;
use App\Listeners\InitiateOnboardingOnHire;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;

it('auto-initiates an onboarding case when an employee with a hire date is created', function () {
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    event(new EmployeeCreated($employee, User::factory()->create()));

    expect(OnboardingCase::where('employee_id', $employee->id)->open()->count())->toBe(1);
});

it('does not auto-initiate when the employee has no hire date', function () {
    // hire_date is NOT NULL at the DB level, so a hire-date-less employee can
    // only exist transiently in memory. Drive the listener directly (the event
    // pipeline serializes models, which a hire_date-less row can't survive) to
    // exercise its defensive `! $employee->hire_date` guard.
    $employee = Employee::factory()->create();
    $employee->hire_date = null;

    (new InitiateOnboardingOnHire(app(OnboardingService::class)))
        ->handle(new EmployeeCreated($employee, User::factory()->create()));

    expect(OnboardingCase::where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('does not create a duplicate case if one is already open', function () {
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    $actor = User::factory()->create();
    event(new EmployeeCreated($employee, $actor));
    event(new EmployeeCreated($employee->fresh(), $actor));

    expect(OnboardingCase::where('employee_id', $employee->id)->count())->toBe(1);
});
