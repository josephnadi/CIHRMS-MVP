<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\EmployeePolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\TicketPolicy;

// ─────────────────────────────────────────────────────────────────────────────
// EmployeePolicy
// ─────────────────────────────────────────────────────────────────────────────

test('employee policy: plain employee cannot view another employee', function () {
    $alice = User::factory()->create(['role' => 'employee']);
    $bob   = User::factory()->create(['role' => 'employee']);
    $dept  = Department::factory()->create();

    Employee::factory()->create(['user_id' => $alice->id, 'department_id' => $dept->id]);
    $bobsRecord = Employee::factory()->create(['user_id' => $bob->id, 'department_id' => $dept->id]);

    $policy = new EmployeePolicy();
    expect($policy->view($alice, $bobsRecord))->toBeFalse();
});

test('employee policy: a user can always view their own employee record', function () {
    $alice = User::factory()->create(['role' => 'employee']);
    $emp = Employee::factory()->create(['user_id' => $alice->id]);

    expect((new EmployeePolicy)->view($alice, $emp))->toBeTrue();
});

test('employee policy: hr admin can update, delete, and view any employee', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $emp = Employee::factory()->create();

    $policy = new EmployeePolicy();
    expect($policy->view($hr, $emp))->toBeTrue();
    expect($policy->update($hr, $emp))->toBeTrue();
    expect($policy->delete($hr, $emp))->toBeTrue();
});

test('employee policy: super admin bypass via before()', function () {
    $sa = User::factory()->create(['role' => 'super_admin']);
    $emp = Employee::factory()->create();

    $policy = new EmployeePolicy();
    expect($policy->before($sa))->toBeTrue();
});

test('employee policy: plain employee cannot delete employees', function () {
    $alice = User::factory()->create(['role' => 'employee']);
    $emp = Employee::factory()->create();

    expect((new EmployeePolicy)->delete($alice, $emp))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// TicketPolicy
// ─────────────────────────────────────────────────────────────────────────────

test('ticket policy: assignee can view and update their own ticket', function () {
    $assignee = User::factory()->create(['role' => 'it_support']);
    $owner = User::factory()->create(['role' => 'employee']);
    $emp = Employee::factory()->create(['user_id' => $owner->id]);
    $ticket = Ticket::factory()->create([
        'employee_id' => $emp->id,
        'assigned_to' => $assignee->id,
    ]);

    $policy = new TicketPolicy();
    expect($policy->view($assignee, $ticket))->toBeTrue();
    expect($policy->update($assignee, $ticket))->toBeTrue();
});

test('ticket policy: ticket owner can view their own ticket', function () {
    $owner = User::factory()->create(['role' => 'employee']);
    $emp = Employee::factory()->create(['user_id' => $owner->id]);
    $ticket = Ticket::factory()->create(['employee_id' => $emp->id]);

    expect((new TicketPolicy)->view($owner, $ticket))->toBeTrue();
});

test('ticket policy: unrelated user without manage permission cannot view or delete', function () {
    $stranger = User::factory()->create(['role' => 'employee']);
    $owner = User::factory()->create(['role' => 'employee']);
    $emp = Employee::factory()->create(['user_id' => $owner->id]);
    $ticket = Ticket::factory()->create(['employee_id' => $emp->id]);

    $policy = new TicketPolicy();
    expect($policy->view($stranger, $ticket))->toBeFalse();
    expect($policy->delete($stranger, $ticket))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// LeaveRequestPolicy
// ─────────────────────────────────────────────────────────────────────────────

test('leave policy: leave requester can view their own request', function () {
    $alice = User::factory()->create(['role' => 'employee']);
    $emp = Employee::factory()->create(['user_id' => $alice->id]);
    $leave = LeaveRequest::factory()->create(['employee_id' => $emp->id]);

    expect((new LeaveRequestPolicy)->view($alice, $leave))->toBeTrue();
});

test('leave policy: other employee cannot view or approve another employees leave', function () {
    $alice = User::factory()->create(['role' => 'employee']);
    $bob   = User::factory()->create(['role' => 'employee']);
    $bobEmp = Employee::factory()->create(['user_id' => $bob->id]);
    $leave = LeaveRequest::factory()->create(['employee_id' => $bobEmp->id]);

    $policy = new LeaveRequestPolicy();
    expect($policy->view($alice, $leave))->toBeFalse();
    expect($policy->approve($alice, $leave))->toBeFalse();
});

test('leave policy: hr admin can approve any leave', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $leave = LeaveRequest::factory()->create();

    expect((new LeaveRequestPolicy)->approve($hr, $leave))->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// PaymentPolicy
// ─────────────────────────────────────────────────────────────────────────────

test('payment policy: employee can view their own payment', function () {
    $alice = User::factory()->create(['role' => 'employee']);
    $emp = Employee::factory()->create(['user_id' => $alice->id]);
    $payment = Payment::factory()->create(['employee_id' => $emp->id]);

    expect((new PaymentPolicy)->view($alice, $payment))->toBeTrue();
});

test('payment policy: employee cannot mark a payment as paid', function () {
    $alice = User::factory()->create(['role' => 'employee']);
    $payment = Payment::factory()->pending()->create();

    expect((new PaymentPolicy)->markPaid($alice, $payment))->toBeFalse();
});

test('payment policy: finance officer can create and mark paid', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $payment = Payment::factory()->pending()->create();

    $policy = new PaymentPolicy();
    expect($policy->create($finance))->toBeTrue();
    expect($policy->markPaid($finance, $payment))->toBeTrue();
});

test('payment policy: viewAny requires payroll.view or payroll.manage', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $finance  = User::factory()->create(['role' => 'finance_officer']);

    $policy = new PaymentPolicy();
    expect($policy->viewAny($employee))->toBeFalse();
    expect($policy->viewAny($finance))->toBeTrue();
});
