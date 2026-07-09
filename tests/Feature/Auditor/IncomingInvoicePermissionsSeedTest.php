<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants auditor vetting + hub, not submit', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    expect($u->hasPermission('incoming_invoices.view'))->toBeTrue();
    expect($u->hasPermission('incoming_invoices.vet'))->toBeTrue();
    expect($u->hasPermission('auditor.hub'))->toBeTrue();
    expect($u->hasPermission('incoming_invoices.submit'))->toBeFalse();
    expect($u->hasPermission('incoming_invoices.approve'))->toBeFalse();
});

it('grants finance_officer submit + post', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    expect($u->hasPermission('incoming_invoices.submit'))->toBeTrue();
    expect($u->hasPermission('incoming_invoices.post'))->toBeTrue();
    expect($u->hasPermission('incoming_invoices.vet'))->toBeFalse();
});

it('grants dept_head submit but not vet/post', function () {
    $u = User::factory()->create(['role' => 'dept_head']);
    expect($u->hasPermission('incoming_invoices.submit'))->toBeTrue();
    expect($u->hasPermission('incoming_invoices.vet'))->toBeFalse();
    expect($u->hasPermission('incoming_invoices.post'))->toBeFalse();
});

it('ceo (wildcard) can approve', function () {
    $u = User::factory()->create(['role' => 'ceo']);
    expect($u->hasPermission('incoming_invoices.approve'))->toBeTrue();
});

it('plain employee has none', function () {
    $u = User::factory()->create(['role' => 'employee']);
    expect($u->hasPermission('incoming_invoices.view'))->toBeFalse();
});
