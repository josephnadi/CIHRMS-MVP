<?php

declare(strict_types=1);

use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('allows hr_admin to create a plan', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);

    actingAs($hr)->post('/benefits/plans', [
        'name' => 'Test Plan', 'code' => 'CTRL-TEST', 'type' => 'health_insurance',
        'monthly_cost' => 100, 'effective_from' => '2026-01-01', 'max_dependants' => 2,
    ])->assertRedirect();

    expect(BenefitPlan::where('code', 'CTRL-TEST')->exists())->toBeTrue();
});

it('forbids a regular employee from creating a plan (RBAC deny)', function () {
    $emp = User::factory()->create(['role' => 'employee']);

    actingAs($emp)->post('/benefits/plans', [
        'name' => 'Forbidden', 'code' => 'NOPE', 'type' => 'health_insurance',
        'monthly_cost' => 100, 'effective_from' => '2026-01-01',
    ])->assertForbidden();
});

it('lets an employee enrol in a plan and submit a claim', function () {
    $employee = Employee::factory()->create();
    $plan = BenefitPlan::create([
        'name' => 'Health', 'code' => 'EMP-FLOW', 'type' => 'health_insurance',
        'monthly_cost' => 200, 'effective_from' => '2026-01-01', 'max_dependants' => 2,
    ]);

    actingAs($employee->user)->post('/benefits/enrol', [
        'plan_id' => $plan->id, 'effective_from' => '2026-02-01',
    ])->assertRedirect();

    $enrolment = $employee->benefitEnrolments()->first();

    actingAs($employee->user)->post('/benefits/claims', [
        'enrolment_id' => $enrolment->id,
        'amount' => 50, 'description' => 'Test claim from controller path.',
    ])->assertRedirect();

    expect($enrolment->claims()->count())->toBe(1);
});
