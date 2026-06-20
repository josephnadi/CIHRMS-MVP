<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
});

it('renders the analytics dashboard for finance_officer, auditor, and super_admin', function () {
    foreach (['finance_officer', 'auditor', 'super_admin'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/finance/analytics?year=2026')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Finance/Analytics/Dashboard')->has('kpis')->has('trends'));
    }
});

it('forbids an employee', function () {
    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->get('/finance/analytics')->assertForbidden();
});

it('exports analytics CSV and PDF', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $csv = $this->actingAs($u)->get('/finance/analytics/export.csv?year=2026&from=2026-01-01&to=2026-12-31');
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $pdf = $this->actingAs($u)->get('/finance/analytics/export.pdf?year=2026');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});
