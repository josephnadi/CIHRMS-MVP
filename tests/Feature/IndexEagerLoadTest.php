<?php

declare(strict_types=1);

use App\Models\BenefitClaim;
use App\Models\BenefitEnrolment;
use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Models\LoanAccount;
use App\Models\User;

// These pages render Resources that walk relations (applicant/approver,
// enrolment.employee.user) which strict-mode lazy-loading turns into a 500 if
// the controller forgets to eager-load them. The fix lives in the index
// eager-loads; these guard against the regression coming back.
//
// NOTE: Laravel only arms instance-level lazy-load prevention when a query
// hydrates 2+ models (Builder::hydrate guards on `count($items) > 1`, the real
// N+1 case). So each test MUST create at least two rows, or the violation
// never fires and the guard is hollow.

it('loans index renders with applicant + approver populated (no lazy-load 500)', function () {
    foreach (range(1, 2) as $i) {
        $applicant = User::factory()->create(['role' => 'employee']);
        $approver  = User::factory()->create(['role' => 'finance_officer']);
        $employee  = Employee::factory()->create(['user_id' => $applicant->id]);

        LoanAccount::factory()->create([
            'employee_id' => $employee->id,
            'applied_by'  => $applicant->id,
            'approved_by' => $approver->id,
        ]);
    }

    $viewer = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['loans.view']]);

    $this->actingAs($viewer)->get(route('loans.index'))->assertOk();
});

it('benefits index renders claims with enrolment.employee.user populated (no lazy-load 500)', function () {
    $user     = User::factory()->create(['role' => 'employee']);
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    // TWO distinct enrolments (same employee, two plans), one claim each. The
    // claims query then eager-loads two distinct enrolment models, so strict
    // mode arms on the enrolment instances and a missing enrolment.employee
    // eager-load surfaces as a lazy-load 500.
    foreach (range(1, 2) as $i) {
        $enrolment = BenefitEnrolment::factory()->create([
            'employee_id' => $employee->id,
            'plan_id'     => BenefitPlan::factory()->create()->id,
        ]);
        BenefitClaim::factory()->create([
            'enrolment_id' => $enrolment->id,
            'decided_by'   => $user->id,
        ]);
    }

    $this->actingAs($user)->get(route('benefits.index'))->assertOk();
});
