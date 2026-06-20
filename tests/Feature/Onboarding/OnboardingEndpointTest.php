<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('lets an authorized user open and view onboarding cases', function () {
    $u = User::factory()->create(['role' => 'employee', 'permissions' => ['onboarding.view', 'onboarding.initiate']]);
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);

    $this->actingAs($u)->post('/onboarding', ['employee_id' => $employee->id])->assertRedirect();
    $case = OnboardingCase::where('employee_id', $employee->id)->firstOrFail();

    $this->actingAs($u)->get('/onboarding')->assertOk();
    $this->actingAs($u)->get("/onboarding/{$case->id}")->assertOk();
});

it('completes a task and then the case', function () {
    $u = User::factory()->create(['role' => 'employee',
        'permissions' => ['onboarding.view', 'onboarding.initiate', 'onboarding.complete']]);
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    $case = app(OnboardingService::class)->initiate($employee, $u);

    foreach ($case->tasks as $t) {
        $action = $t->is_required ? 'complete' : 'skip';
        $this->actingAs($u)->post("/onboarding/{$case->id}/tasks/{$t->id}", ['action' => $action, 'reason' => 'x'])->assertRedirect();
    }

    $this->actingAs($u)->post("/onboarding/{$case->id}/complete")->assertRedirect();
    expect($case->fresh()->status->value)->toBe('completed');
});

it('forbids a user without onboarding permission', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/onboarding')->assertForbidden();
});
