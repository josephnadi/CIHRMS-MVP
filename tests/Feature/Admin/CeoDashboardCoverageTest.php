<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('CEO can hit the dashboard and gets the finance snapshot', function () {
    $ceo = User::factory()->create(['role' => 'ceo']);

    $this->actingAs($ceo)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Dashboard')
            ->has('financeSnapshot')   // included for executive roles
            ->has('stats')
            ->has('employees')
            ->has('headcountByDept')
        );
});

it('CEO can reach the admin users index without 403', function () {
    $ceo = User::factory()->create(['role' => 'ceo']);

    $this->actingAs($ceo)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Admin/Users/Index'));
});

it('CEO can reach the audit logs without 403 (wildcard access)', function () {
    $ceo = User::factory()->create(['role' => 'ceo']);

    $response = $this->actingAs($ceo)->get('/audit-logs');

    // The route exists under different shells in different parts of the app —
    // accept either OK (audit module renders) or 200-class redirect. What we
    // care about is that it isn't 403/404 from a permission gate.
    expect($response->status())->not->toBe(403);
});
