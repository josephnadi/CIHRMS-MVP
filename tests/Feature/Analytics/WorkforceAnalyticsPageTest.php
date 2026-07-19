<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('grants workforce.analytics.view to hr_admin', function () {
    $user = User::factory()->create(['role' => 'hr_admin']);

    expect($user->hasPermission('workforce.analytics.view'))->toBeTrue();
});

it('denies workforce.analytics.view to a plain employee', function () {
    $user = User::factory()->create(['role' => 'employee']);

    expect($user->hasPermission('workforce.analytics.view'))->toBeFalse();
});

it('renders the workforce dashboard for a permissioned user', function () {
    // The Vue page (Analytics/Workforce.vue) ships in Task 5; withoutVite() lets
    // the Inertia root view render without a compiled asset/manifest entry so this
    // HTTP-layer test can assert the component name + props now.
    $this->withoutVite();

    $user = User::factory()->create(['role' => 'hr_admin']);
    $dept = Department::factory()->create();
    Employee::factory()->count(3)->create(['department_id' => $dept->id, 'status' => 'active']);

    $this->actingAs($user)
        ->get(route('analytics.workforce'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            // 2nd arg (shouldExist=false): Analytics/Workforce.vue ships in Task 5;
            // remove this override once that file lands so the existence check
            // (config inertia.testing.ensure_pages_exist) re-engages.
            ->component('Analytics/Workforce', false)
            ->has('metrics.kpis.headcount')
            ->has('metrics.series')
            ->has('departments'));
});

it('403s for a user without workforce.analytics.view', function () {
    $user = User::factory()->create(['role' => 'employee']);

    $this->actingAs($user)->get(route('analytics.workforce'))->assertForbidden();
});

it('narrows headcount to the selected department', function () {
    $this->withoutVite();

    $user = User::factory()->create(['role' => 'hr_admin']);
    $a = Department::factory()->create();
    $b = Department::factory()->create();
    Employee::factory()->count(2)->create(['department_id' => $a->id, 'status' => 'active']);
    Employee::factory()->count(5)->create(['department_id' => $b->id, 'status' => 'active']);

    $this->actingAs($user)
        ->get(route('analytics.workforce', ['department_id' => $a->id]))
        ->assertInertia(fn (Assert $p) => $p->where('metrics.kpis.headcount', 2));
});
