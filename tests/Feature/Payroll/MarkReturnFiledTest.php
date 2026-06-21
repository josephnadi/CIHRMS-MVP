<?php

declare(strict_types=1);

use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GhanaStatutoryReferenceSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    // Filing a return now posts a remittance JE — set up the GL prerequisites.
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    (new OrgBankAccountSeeder())->run();
});

function seedReturn(): StatutoryReturn
{
    $run = PayrollRun::create([
        'reference' => 'PR-' . uniqid(), 'period_year' => 2026, 'period_month' => 6,
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'status' => 'approved', 'created_by' => User::factory()->create()->id,
    ]);

    return StatutoryReturn::create([
        'payroll_run_id' => $run->id, 'kind' => 'paye', 'file_path' => 'returns/x.csv',
        'total_amount' => 1000, 'record_count' => 3, 'generated_at' => now(),
    ]);
}

it('records a return as filed for a user with statutory.remit', function () {
    $r = seedReturn();
    $u = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['statutory.remit']]);

    $this->actingAs($u)
        ->post("/payroll-runs/{$r->payroll_run_id}/returns/{$r->id}/mark-filed", ['reference' => 'GRA-2026-06'])
        ->assertRedirect();

    expect($r->fresh()->submitted_at)->not->toBeNull()
        ->and($r->fresh()->submission_reference)->toBe('GRA-2026-06');
});

it('requires a reference', function () {
    $r = seedReturn();
    $u = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['statutory.remit']]);

    $this->actingAs($u)
        ->post("/payroll-runs/{$r->payroll_run_id}/returns/{$r->id}/mark-filed", ['reference' => ''])
        ->assertSessionHasErrors('reference');
});

it('forbids a user without statutory.remit', function () {
    $r = seedReturn();
    $u = User::factory()->create(['role' => 'employee']);

    $this->actingAs($u)
        ->post("/payroll-runs/{$r->payroll_run_id}/returns/{$r->id}/mark-filed", ['reference' => 'X'])
        ->assertForbidden();
});
