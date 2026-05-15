<?php

declare(strict_types=1);

use App\Models\Policy;
use App\Models\User;
use App\Services\GovernanceService;

use function Pest\Laravel\actingAs;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('hr_admin can create a policy', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);

    actingAs($hr)->post('/governance/policies', [
        'title' => 'Working Hours', 'category' => 'hr', 'initial_body' => 'Stick to schedule.',
    ])->assertRedirect();

    expect(Policy::where('slug', 'working-hours')->exists())->toBeTrue();
});

it('forbids an employee from publishing a version (RBAC deny)', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $emp = User::factory()->create(['role' => 'employee']);
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Test', 'category' => 'hr', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();

    actingAs($emp)->patch("/governance/versions/{$v1->id}/publish", [
        'effective_from' => '2026-06-01',
    ])->assertForbidden();
});

it('rejects acknowledgement with mismatched signed name', function () {
    $hr = User::factory()->create(['role' => 'hr_admin', 'name' => 'Alice Mensah']);
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Sig Test', 'category' => 'compliance', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();
    app(GovernanceService::class)->publish($v1, $hr, new \DateTimeImmutable('2026-06-01'));

    actingAs($hr)->post("/governance/versions/{$v1->id}/ack", [
        'signed_full_name' => 'Bob Wrongname',
    ])->assertStatus(422);
});
