<?php

declare(strict_types=1);

use App\Enums\CorrectionStatus;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

it('lets an employee submit a correction request', function () {
    $emp = Employee::factory()->create();
    // Ensure the user has the employee role assignment via the seeder backfill
    $emp->user->refresh();

    actingAs($emp->user)->post('/attendance/corrections', [
        'requested_event_at'  => now()->subDay()->toIso8601String(),
        'requested_direction' => 'in',
        'reason'              => 'Biometric reader was down yesterday morning.',
    ])->assertRedirect();

    expect(AttendanceCorrection::count())->toBeGreaterThan(0);
});

it('lets a manager approve a correction and applies it as a manual record', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $manager->refresh();

    $emp = Employee::factory()->create();
    $correction = AttendanceCorrection::create([
        'employee_id'         => $emp->id,
        'requester_id'        => $emp->user_id,
        'requested_event_at'  => now()->subDay(),
        'requested_direction' => 'in',
        'reason'              => 'Network down for the test scenario.',
        'status'              => CorrectionStatus::Pending,
    ]);

    actingAs($manager)->patch("/attendance/corrections/{$correction->id}/review", [
        'decision'       => 'approve',
        'decision_notes' => 'Verified with IT.',
    ])->assertRedirect();

    expect($correction->fresh()->status)->toBe(CorrectionStatus::Approved);
    expect(\App\Models\AttendanceRecord::where('employee_id', $emp->id)->count())->toBeGreaterThan(0);
});

it('forbids an employee from approving (RBAC deny)', function () {
    $other = Employee::factory()->create();
    $correction = AttendanceCorrection::create([
        'employee_id'         => $other->id,
        'requester_id'        => $other->user_id,
        'requested_event_at'  => now()->subDay(),
        'requested_direction' => 'in',
        'reason'              => 'Reason for testing the RBAC deny path.',
        'status'              => CorrectionStatus::Pending,
    ]);

    $rando = User::factory()->create(['role' => 'employee']);

    actingAs($rando)->patch("/attendance/corrections/{$correction->id}/review", [
        'decision' => 'approve',
    ])->assertForbidden();
});

it('rejects approving an already-decided correction', function () {
    $manager = User::factory()->create(['role' => 'hr_admin']);
    $emp = Employee::factory()->create();
    $correction = AttendanceCorrection::create([
        'employee_id'         => $emp->id,
        'requester_id'        => $emp->user_id,
        'requested_event_at'  => now()->subDay(),
        'requested_direction' => 'in',
        'reason'              => 'Reason text long enough.',
        'status'              => CorrectionStatus::Approved,
        'reviewer_id'         => $manager->id,
        'reviewed_at'         => now()->subHour(),
    ]);

    expect(fn () => app(AttendanceService::class)->approveCorrection($correction, $manager))
        ->toThrow(\DomainException::class);
});
