<?php

declare(strict_types=1);

use App\Enums\BudgetStatus;
use App\Models\Budget;
use App\Models\GlAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
});

it('finance_officer can view the budgets admin page', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/budgets?year=2026')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Budgets/Index')->where('budget.status', 'draft'));
});

it('employee is forbidden from budgets', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/budgets')->assertForbidden();
});

it('upserts a budget line, approves, blocks edits, then reverts', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    $this->actingAs($u)->post('/finance/budgets/line', ['year' => 2026, 'gl_account_id' => $acc->id, 'annual_amount' => 120000])->assertRedirect();
    $budget = Budget::firstOrFail();
    expect((float) $budget->lines()->first()->annual_amount)->toBe(120000.0);

    $this->actingAs($u)->post('/finance/budgets/approve', ['year' => 2026])->assertRedirect();
    expect($budget->fresh()->status)->toBe(BudgetStatus::Approved);

    // editing an approved budget is rejected (validation error from the caught DomainException)
    $this->actingAs($u)->post('/finance/budgets/line', ['year' => 2026, 'gl_account_id' => $acc->id, 'annual_amount' => 1])
        ->assertSessionHasErrors();

    $this->actingAs($u)->post('/finance/budgets/revert', ['year' => 2026])->assertRedirect();
    expect($budget->fresh()->status)->toBe(BudgetStatus::Draft);
});
