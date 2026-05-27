<?php

use App\Models\Customer;
use App\Models\Department;
use App\Models\User;

// ── M7: Department update/delete defense-in-depth ──

it('blocks dept update from a user without employees.manage even if middleware were removed', function () {
    $dept = Department::factory()->create();
    $u    = User::factory()->create(['role' => 'employee', 'permissions' => []]);

    $this->actingAs($u)
        ->patch(route('departments.update', $dept), ['name' => 'Hacked'])
        ->assertForbidden();
});

it('blocks dept delete from a dept-head (even though they can update)', function () {
    $dept = Department::factory()->create();
    // dept-head has no `employees.manage`; policy gates delete on that perm
    $u = User::factory()->create(['role' => 'manager', 'permissions' => []]);

    $this->actingAs($u)
        ->delete(route('departments.destroy', $dept))
        ->assertForbidden();
});

// ── M8: Finance show/approve/cancel defense-in-depth ──

it('blocks AR invoice show from a user without ar_invoices.view', function () {
    $u  = User::factory()->create(['role' => 'employee', 'permissions' => []]);
    $iv = \App\Models\ArInvoice::factory()->create();

    $this->actingAs($u)
        ->get(route('finance.ar-invoices.show', $iv))
        ->assertForbidden();
});

// ── M9: Customer statement IDOR ──

it('blocks customer statement from a caller without statements.view', function () {
    $c = Customer::factory()->create();
    $u = User::factory()->create(['role' => 'employee', 'permissions' => []]);

    $this->actingAs($u)
        ->get(route('finance.statements.show', $c))
        ->assertForbidden();
});
