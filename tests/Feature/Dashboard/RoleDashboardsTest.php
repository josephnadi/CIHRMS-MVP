<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\DashboardService;

beforeEach(function () {
    $this->dept = Department::factory()->create(['name' => 'Treasury']);
});

it('finance_officer dashboard loads with a finance snapshot', function () {
    $user = User::factory()->create(['role' => 'finance_officer']);

    PayrollRun::create([
        'reference'    => 'PR-2026-05-CALC01',
        'status'       => 'calculated',
        'period_year'  => 2026,
        'period_month' => 5,
        'period_start' => '2026-05-01',
        'period_end'   => '2026-05-31',
        'net_total'    => 100_000,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')
            ->has('financeSnapshot.payroll.calculated.count')
            ->where('financeSnapshot.payroll.calculated.count', 1)
            ->has('financeSnapshot.disbursement')
            ->has('financeSnapshot.statutory')
            ->where('managerSnapshot', null)
            ->where('deptHeadSnapshot', null)
        );
});

it('manager dashboard loads with a manager snapshot', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $managerEmp = Employee::factory()->create([
        'user_id'       => $manager->id,
        'department_id' => $this->dept->id,
    ]);

    Employee::factory()->count(3)->create([
        'department_id' => $this->dept->id,
        'manager_id'    => $managerEmp->id,
    ]);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')
            ->where('managerSnapshot.team_size', 3)
            ->has('managerSnapshot.pending_leave_list')
            ->has('managerSnapshot.open_tickets_list')
            ->where('financeSnapshot', null)
        );
});

it('dept_head dashboard loads with both snapshots', function () {
    $head = User::factory()->create(['role' => 'dept_head']);
    $headEmp = Employee::factory()->create([
        'user_id'       => $head->id,
        'department_id' => $this->dept->id,
    ]);

    Employee::factory()->count(5)->create([
        'department_id' => $this->dept->id,
        'manager_id'    => $headEmp->id,
    ]);

    $this->actingAs($head)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')
            ->where('deptHeadSnapshot.headcount', 6)  // 5 + head
            ->where('deptHeadSnapshot.dept.name', 'Treasury')
            ->has('managerSnapshot.team_size')
        );
});

it('it_support / marketing / auditor dashboards load without role snapshots', function () {
    foreach (['it_support', 'marketing', 'auditor'] as $role) {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard')
                ->where('financeSnapshot', null)
                ->where('managerSnapshot', null)
                ->where('deptHeadSnapshot', null)
                ->has('activityFeed')
            );
    }
});

it('finance snapshot rolls up payroll runs by status', function () {
    $make = fn (string $status, float $net, string $code) => PayrollRun::create([
        'reference'    => "PR-2026-05-{$code}",
        'status'       => $status,
        'period_year'  => 2026,
        'period_month' => 5,
        'period_start' => '2026-05-01',
        'period_end'   => '2026-05-31',
        'net_total'    => $net,
    ]);

    $make('draft',      1000, 'DRAFT1');
    $make('calculated', 2000, 'CALC01');
    $make('calculated', 3000, 'CALC02');
    $make('approved',   4000, 'APPR01');

    $snapshot = app(DashboardService::class)->getFinanceSnapshot();

    expect($snapshot['payroll']['draft']['count'])->toBe(1);
    expect($snapshot['payroll']['calculated']['count'])->toBe(2);
    expect($snapshot['payroll']['calculated']['net'])->toBe(5000.0);
    expect($snapshot['payroll']['approved']['count'])->toBe(1);
});
