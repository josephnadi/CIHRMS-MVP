<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('login page exposes ssoProviders inline (not deferred)', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Auth/Login')
            ->has('ssoProviders')
        );
});

it('authenticated dashboard does NOT include ssoProviders in shared props', function () {
    $u = User::factory()->create(['role' => 'employee']);

    $this->actingAs($u)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->missing('ssoProviders'));
});

it('authenticated dashboard defers notifications + ticker (not in initial payload)', function () {
    $u = User::factory()->create(['role' => 'employee']);

    $this->actingAs($u)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->missing('notifications')
            ->missing('notificationCount')
            ->missing('announcementTicker')
        );
});

it('auth.permissions stays in the initial payload (layout needs it for can() gates)', function () {
    $u = User::factory()->create(['role' => 'employee']);

    $this->actingAs($u)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->has('auth.permissions')
            ->has('auth.roles')
        );
});
