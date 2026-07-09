<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('auditor can open the hub', function () {
    $this->actingAs(User::factory()->create(['role' => 'auditor']))
        ->get('/auditor')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Auditor/Hub'));
});

it('plain employee cannot open the hub', function () {
    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->get('/auditor')->assertForbidden();
});
