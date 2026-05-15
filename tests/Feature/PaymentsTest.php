<?php

use App\Enums\PaymentStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\User;

beforeEach(function () {
    $this->dept = Department::factory()->create();
    $this->finance = User::factory()->create(['role' => 'finance_officer']);
    $this->employeeUser = User::factory()->create(['role' => 'employee']);
    $this->employee = Employee::factory()->active()->create([
        'user_id'       => $this->employeeUser->id,
        'department_id' => $this->dept->id,
    ]);
});

test('finance officer can record a raw payment', function () {
    $this->actingAs($this->finance)
        ->post(route('payments.store'), [
            'employee_id' => $this->employee->id,
            'description' => 'Travel reimbursement',
            'amount'      => 750.00,
            'currency'    => 'GHS',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('payments', [
        'employee_id' => $this->employee->id,
        'description' => 'Travel reimbursement',
        'amount'      => 750.00,
        'status'      => PaymentStatus::Pending->value,
    ]);
});

test('finance officer can mark a payment as paid and paid_at is stamped', function () {
    $payment = Payment::factory()->pending()->create(['employee_id' => $this->employee->id]);

    $this->actingAs($this->finance)
        ->patch(route('payments.paid', $payment))
        ->assertRedirect();

    $payment->refresh();
    expect($payment->status->value)->toBe(PaymentStatus::Paid->value);
    expect($payment->paid_at)->not->toBeNull();
    expect($payment->processed_by)->toBe($this->finance->id);
});

test('payments index renders for users with payroll.manage', function () {
    Payment::factory()->count(3)->create(['employee_id' => $this->employee->id]);

    $this->actingAs($this->finance)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Payments/Index')
            ->has('payments.data', 3)
            ->has('analytics')
        );
});

test('payslip preview returns calculated totals', function () {
    $response = $this->actingAs($this->finance)
        ->postJson(route('payments.payslip.preview'), [
            'basic'      => 5000,
            'allowances' => [
                ['label' => 'Transport', 'amount' => 500],
            ],
        ]);

    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKey('totals');
    expect($data['totals'])->toHaveKey('net_pay');
    expect($data['totals']['net_pay'])->toBeFloat();
    expect($data['totals']['net_pay'])->toBeGreaterThan(0);
});

test('payslip generation creates a payment with payroll items', function () {
    $this->actingAs($this->finance)
        ->post(route('payments.payslip.generate'), [
            'employee_id' => $this->employee->id,
            'period'      => '2026-05',
            'basic'       => 4000,
            'allowances'  => [
                ['label' => 'Transport', 'amount' => 400],
            ],
            'mark_paid'   => false,
        ])
        ->assertRedirect();

    $payment = Payment::where('employee_id', $this->employee->id)->latest()->first();
    expect($payment)->not->toBeNull();
    expect($payment->status->value)->toBe(PaymentStatus::Pending->value);

    // Payroll items: at least Basic Salary + Transport + SSNIT Tier 1 + PAYE
    expect($payment->items()->count())->toBeGreaterThanOrEqual(4);
});
