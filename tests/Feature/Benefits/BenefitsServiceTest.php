<?php

declare(strict_types=1);

use App\Enums\BenefitEnrolmentStatus;
use App\Enums\ClaimStatus;
use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Models\User;
use App\Services\BenefitsService;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('creates a benefit plan', function () {
    $plan = app(BenefitsService::class)->createPlan([
        'name' => 'Premium Health',
        'code' => 'HLTH-PREMIUM',
        'type' => 'health_insurance',
        'monthly_cost' => 500,
        'employee_contribution_percentage' => 25,
        'effective_from' => '2026-01-01',
        'max_dependants' => 4,
    ]);

    expect($plan)->toBeInstanceOf(BenefitPlan::class);
    expect($plan->code)->toBe('HLTH-PREMIUM');
});

it('enrols an employee with computed premium', function () {
    $emp = Employee::factory()->create();
    $plan = BenefitPlan::create([
        'name' => 'Standard Health', 'code' => 'HLTH-STD', 'type' => 'health_insurance',
        'monthly_cost' => 200, 'employee_contribution_percentage' => 25,
        'effective_from' => '2026-01-01', 'max_dependants' => 2,
    ]);

    $enrolment = app(BenefitsService::class)->enrol($plan, $emp, new \DateTimeImmutable('2026-02-01'));

    expect((float) $enrolment->monthly_premium)->toBe(50.00);
    expect($enrolment->status)->toBe(BenefitEnrolmentStatus::Active);
});

it('prevents enrolment in an inactive plan', function () {
    $emp = Employee::factory()->create();
    $plan = BenefitPlan::create([
        'name' => 'Old Health', 'code' => 'HLTH-OLD', 'type' => 'health_insurance',
        'monthly_cost' => 200, 'is_active' => false,
        'effective_from' => '2024-01-01', 'max_dependants' => 0,
    ]);

    expect(fn () => app(BenefitsService::class)->enrol($plan, $emp, new \DateTimeImmutable('2026-02-01')))
        ->toThrow(\DomainException::class, 'not active');
});

it('rejects more dependants than plan allows', function () {
    $emp = Employee::factory()->create();
    $plan = BenefitPlan::create([
        'name' => 'Tight Plan', 'code' => 'HLTH-TIGHT', 'type' => 'health_insurance',
        'monthly_cost' => 200, 'effective_from' => '2026-01-01', 'max_dependants' => 1,
    ]);
    app(BenefitsService::class)->enrol($plan, $emp, new \DateTimeImmutable('2026-02-01'));

    app(BenefitsService::class)->addDependant($emp, [
        'full_name' => 'Spouse', 'relationship' => 'spouse', 'date_of_birth' => '1990-01-01',
    ]);

    expect(fn () => app(BenefitsService::class)->addDependant($emp, [
        'full_name' => 'Child', 'relationship' => 'child', 'date_of_birth' => '2010-01-01',
    ]))->toThrow(\DomainException::class, 'cap');
});

it('generates a CLM- reference on submit', function () {
    $emp = Employee::factory()->create();
    $plan = BenefitPlan::create([
        'name' => 'Health', 'code' => 'HLTH-CLM', 'type' => 'health_insurance',
        'monthly_cost' => 200, 'effective_from' => '2026-01-01', 'max_dependants' => 2,
    ]);
    $enrolment = app(BenefitsService::class)->enrol($plan, $emp, new \DateTimeImmutable('2026-02-01'));

    $claim = app(BenefitsService::class)->submitClaim($enrolment, [
        'amount' => 150, 'description' => 'Outpatient consultation visit',
    ]);

    expect($claim->claim_reference)->toStartWith('CLM-');
    expect(strlen($claim->claim_reference))->toBe(12);
});

it('transitions claim submitted -> reviewing -> approved -> paid', function () {
    $emp = Employee::factory()->create();
    $plan = BenefitPlan::create([
        'name' => 'Health', 'code' => 'HLTH-TRA', 'type' => 'health_insurance',
        'monthly_cost' => 200, 'effective_from' => '2026-01-01', 'max_dependants' => 2,
    ]);
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $enrolment = app(BenefitsService::class)->enrol($plan, $emp, new \DateTimeImmutable('2026-02-01'));
    $claim = app(BenefitsService::class)->submitClaim($enrolment, [
        'amount' => 100, 'description' => 'Lab test for the test scenario',
    ]);

    app(BenefitsService::class)->decideClaim($claim, ClaimStatus::Reviewing, $hr);
    expect($claim->fresh()->status)->toBe(ClaimStatus::Reviewing);

    app(BenefitsService::class)->decideClaim($claim->fresh(), ClaimStatus::Approved, $hr);
    expect($claim->fresh()->status)->toBe(ClaimStatus::Approved);

    app(BenefitsService::class)->decideClaim($claim->fresh(), ClaimStatus::Paid, $hr);
    expect($claim->fresh()->status)->toBe(ClaimStatus::Paid);
});

it('rejects illegal transitions like rejected -> paid', function () {
    $emp = Employee::factory()->create();
    $plan = BenefitPlan::create([
        'name' => 'Health', 'code' => 'HLTH-ILL', 'type' => 'health_insurance',
        'monthly_cost' => 200, 'effective_from' => '2026-01-01', 'max_dependants' => 2,
    ]);
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $enrolment = app(BenefitsService::class)->enrol($plan, $emp, new \DateTimeImmutable('2026-02-01'));
    $claim = app(BenefitsService::class)->submitClaim($enrolment, [
        'amount' => 100, 'description' => 'Test for illegal-transition.',
    ]);
    app(BenefitsService::class)->decideClaim($claim, ClaimStatus::Rejected, $hr);

    expect(fn () => app(BenefitsService::class)->decideClaim($claim->fresh(), ClaimStatus::Paid, $hr))
        ->toThrow(\DomainException::class, 'Illegal');
});
