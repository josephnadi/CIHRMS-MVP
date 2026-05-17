<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Privacy\ErasureService;

beforeEach(function () {
    $this->svc = app(ErasureService::class);
});

it('tombstones the user row with an [ERASED] marker', function () {
    $u = User::factory()->create(['name' => 'Real Person', 'email' => 'real@example.com']);

    $log = $this->svc->erase($u, 'DSR-2026-TEST');

    $fresh = $u->fresh();
    expect($fresh->name)->toContain('[ERASED');
    expect($fresh->email)->toMatch('/^erased-\d+@example\.invalid$/');
    expect($fresh->two_factor_secret)->toBeNull();

    expect($log['redacted'])->toBeArray()->not->toBeEmpty();
    expect(collect($log['redacted'])->pluck('table'))->toContain('users');
});

it('redacts employee PII but keeps the row for FK integrity', function () {
    $u = User::factory()->create();
    $dept = Department::factory()->create();
    $emp = Employee::factory()->create([
        'user_id' => $u->id, 'department_id' => $dept->id,
        'phone' => '+233200000099', 'address' => '12 Liberation Rd, Accra',
        'national_id' => 'GHA-123456789-0',
    ]);

    $this->svc->erase($u, 'DSR-2026-TEST');

    $fresh = $emp->fresh();
    expect($fresh->phone)->toBeNull();
    expect($fresh->address)->toBeNull();
    expect($fresh->national_id)->toBeNull();
    // Row itself still exists
    expect(Employee::find($emp->id))->not->toBeNull();
});

it('reports statutory holds (payroll 6yr, SSNIT 7yr) in the tombstone log', function () {
    $u = User::factory()->create();
    $dept = Department::factory()->create();
    Employee::factory()->create(['user_id' => $u->id, 'department_id' => $dept->id]);

    $log = $this->svc->erase($u, 'DSR-2026-TEST');

    $heldTables = collect($log['held_back'])->pluck('table')->all();
    expect($heldTables)->toContain('identity_verifications');     // SSNIT retention
    expect($heldTables)->toContain('audit_logs');                  // chain integrity
});
