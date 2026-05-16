<?php

use App\Enums\PerformanceContractStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Services\Performance\PerformanceContractService;

beforeEach(function () {
    $dept = Department::factory()->create();
    $this->employeeUser = User::factory()->create(['role' => 'employee']);
    $this->supervisorUser = User::factory()->create(['role' => 'manager']);

    $this->employee = Employee::factory()->create([
        'department_id' => $dept->id, 'user_id' => $this->employeeUser->id, 'status' => 'active',
    ]);
    $this->supervisor = Employee::factory()->create([
        'department_id' => $dept->id, 'user_id' => $this->supervisorUser->id, 'status' => 'active',
    ]);

    $this->cycle = ReviewCycle::create([
        'name' => 'H1 2026', 'cadence' => 'half_year',
        'starts_at' => '2026-01-01', 'ends_at' => '2026-06-30',
        'status' => 'open',
    ]);

    $this->actor = User::factory()->create(['role' => 'hr_admin']);
    $this->svc = app(PerformanceContractService::class);
});

it('drafts a contract with KPIs whose weights sum to 100', function () {
    $contract = $this->svc->draft($this->cycle, $this->employee, $this->supervisor, [
        ['name' => 'Customer satisfaction', 'weight' => 40, 'target' => 90, 'scorecard' => 'customer'],
        ['name' => 'Reports filed on time', 'weight' => 30, 'target' => 12, 'scorecard' => 'process'],
        ['name' => 'Cost savings (GHS k)',  'weight' => 30, 'target' => 50, 'scorecard' => 'financial'],
    ], $this->actor);

    expect($contract->status)->toBe(PerformanceContractStatus::Draft);
    expect($contract->kpis)->toHaveCount(3);
});

it('refuses KPIs whose weights do not sum to 100', function () {
    expect(fn () => $this->svc->draft($this->cycle, $this->employee, $this->supervisor, [
        ['name' => 'A', 'weight' => 40, 'target' => 10],
        ['name' => 'B', 'weight' => 50, 'target' => 10],
    ], $this->actor))->toThrow(\DomainException::class, 'KPI weights must sum to 100');
});

it('activates only after BOTH employee and supervisor sign', function () {
    $contract = $this->svc->draft($this->cycle, $this->employee, $this->supervisor, [
        ['name' => 'KPI A', 'weight' => 100, 'target' => 10],
    ], $this->actor);

    $contract = $this->svc->sendForSignature($contract);
    expect($contract->status)->toBe(PerformanceContractStatus::PendingSign);

    // Employee signs first
    $contract = $this->svc->sign($contract, $this->employeeUser);
    expect($contract->status)->toBe(PerformanceContractStatus::PendingSign);   // still pending
    expect($contract->employee_signed_at)->not->toBeNull();

    // Supervisor signs → activated
    $contract = $this->svc->sign($contract, $this->supervisorUser);
    expect($contract->status)->toBe(PerformanceContractStatus::Active);
    expect($contract->isFullySigned())->toBeTrue();
});

it('evaluates to achieved when weighted score ≥ 60', function () {
    $contract = $this->svc->draft($this->cycle, $this->employee, $this->supervisor, [
        ['id' => 'kpi-a', 'name' => 'A', 'weight' => 50, 'target' => 100],
        ['id' => 'kpi-b', 'name' => 'B', 'weight' => 50, 'target' => 100],
    ], $this->actor);
    $contract = $this->svc->sendForSignature($contract);
    $this->svc->sign($contract, $this->employeeUser);
    $contract = $this->svc->sign($contract, $this->supervisorUser);

    $contract = $this->svc->evaluate($contract, [
        'kpi-a' => 80,   // 80% of target
        'kpi-b' => 60,   // 60% of target
    ], $this->actor);

    // Weighted: (80 × 50 + 60 × 50) / 100 = 70
    expect((float) $contract->weighted_achievement)->toBe(70.0);
    expect($contract->status)->toBe(PerformanceContractStatus::Achieved);
});

it('evaluates to missed when weighted score < 60', function () {
    $contract = $this->svc->draft($this->cycle, $this->employee, $this->supervisor, [
        ['id' => 'kpi-a', 'name' => 'A', 'weight' => 100, 'target' => 100],
    ], $this->actor);
    $contract = $this->svc->sendForSignature($contract);
    $this->svc->sign($contract, $this->employeeUser);
    $contract = $this->svc->sign($contract, $this->supervisorUser);

    $contract = $this->svc->evaluate($contract, ['kpi-a' => 30], $this->actor);

    expect((float) $contract->weighted_achievement)->toBe(30.0);
    expect($contract->status)->toBe(PerformanceContractStatus::Missed);
});
