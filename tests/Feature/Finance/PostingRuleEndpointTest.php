<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\PostingAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new PostingAccountSeeder())->run();
});

it('finance_officer can view the posting rules page', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/posting-rules')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/PostingRules/Index'));
});

it('employee is forbidden from the posting rules page', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/posting-rules')->assertForbidden();
});

it('re-points a non-locked rule to another account of the same type', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $rule = PostingAccount::where('slug', 'payroll.allowance_expense')->firstOrFail(); // not locked, expense
    $other = GlAccount::where('code', '5200')->firstOrFail(); // Operations Expense (expense)

    $this->actingAs($u)
        ->patch("/finance/posting-rules/{$rule->id}", ['gl_account_id' => $other->id])
        ->assertRedirect();

    expect($rule->fresh()->gl_account_id)->toBe($other->id);
});

it('rejects re-pointing a locked rule', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $rule = PostingAccount::where('slug', 'payroll.net_pay_payable')->firstOrFail(); // locked
    $other = GlAccount::where('code', '2100')->firstOrFail();

    $this->actingAs($u)
        ->patch("/finance/posting-rules/{$rule->id}", ['gl_account_id' => $other->id])
        ->assertSessionHasErrors('gl_account_id');

    expect($rule->fresh()->gl_account_id)->not->toBe($other->id);
});

it('rejects re-pointing to an account of a different type', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $rule = PostingAccount::where('slug', 'payroll.allowance_expense')->firstOrFail(); // expense
    $income = GlAccount::where('code', '4100')->firstOrFail(); // income

    $this->actingAs($u)
        ->patch("/finance/posting-rules/{$rule->id}", ['gl_account_id' => $income->id])
        ->assertSessionHasErrors('gl_account_id');
});

it('rejects re-pointing to a soft-deleted (archived) account', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $rule = PostingAccount::where('slug', 'payroll.allowance_expense')->firstOrFail();
    $archived = GlAccount::where('code', '5200')->firstOrFail(); // expense, same type
    $archived->delete(); // soft delete

    $this->actingAs($u)
        ->patch("/finance/posting-rules/{$rule->id}", ['gl_account_id' => $archived->id])
        ->assertSessionHasErrors('gl_account_id');

    expect($rule->fresh()->gl_account_id)->not->toBe($archived->id);
});
